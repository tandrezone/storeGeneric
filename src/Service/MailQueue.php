<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Infrastructure\Mailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reliable email on top of Mailer. deliver() sends right away and, if the
 * mail server refuses, keeps the message for another try, so a transient SMTP
 * failure doesn't lose an order confirmation. queue() only stores the message
 * (bulk mail). processDue() — `bin/console mail:process`, from cron — sends
 * what is waiting, with a growing delay between attempts.
 */
final class MailQueue
{
    /** Minutes to wait after the 1st, 2nd, … failed attempt. */
    private const BACKOFF_MINUTES = [5, 30, 120, 360, 1440];
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends now; on failure stores the message for retries.
     *
     * @param list<array{filename: string, type: string, content: string}> $attachments
     * @return bool true when it was delivered or is safely queued
     */
    public function deliver(string $to, string $subject, string $html, string $text, ?string $replyTo = null, array $attachments = []): bool
    {
        try {
            $this->mailer->send($to, $subject, $html, $text, $replyTo, $attachments);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Email delivery failed, queued for retry', ['to' => $to, 'error' => $e->getMessage()]);
            try {
                $this->store($to, $subject, $html, $text, $replyTo, 1, $e->getMessage(), $attachments);

                return true;
            } catch (Throwable $storeError) {
                $this->logger->error('Email could not be queued', ['to' => $to, 'error' => $storeError->getMessage()]);

                return false;
            }
        }
    }

    /** Stores a message to be sent by processDue(). @param list<array{filename: string, type: string, content: string}> $attachments */
    public function queue(string $to, string $subject, string $html, string $text, ?string $replyTo = null, array $attachments = []): int
    {
        return $this->store($to, $subject, $html, $text, $replyTo, 0, null, $attachments);
    }

    /**
     * Sends due messages.
     *
     * @return array{sent: int, retry: int, failed: int}
     */
    public function processDue(int $limit = 50): array
    {
        $pdo = $this->db->pdo();
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0];

        $due = $pdo->prepare('
            SELECT id FROM mail_queue
            WHERE sent_at IS NULL AND failed_at IS NULL AND next_attempt_at <= NOW()
            ORDER BY next_attempt_at, id LIMIT ' . max(1, $limit));
        $due->execute();

        foreach ($due->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            // Claim the row (push it into the future) so overlapping cron runs can't both send it.
            $claim = $pdo->prepare('UPDATE mail_queue SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = :id AND sent_at IS NULL AND failed_at IS NULL AND next_attempt_at <= NOW()');
            $claim->execute(['id' => $id]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $row = $pdo->query('SELECT * FROM mail_queue WHERE id = ' . (int) $id)->fetch();

            try {
                $this->mailer->send((string) $row['to_email'], (string) $row['subject'], (string) $row['body_html'], (string) $row['body_text'], $row['reply_to'] !== null ? (string) $row['reply_to'] : null, self::decodeAttachments($row['attachments'] ?? null));
                $pdo->prepare('UPDATE mail_queue SET sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = :id')->execute(['id' => $id]);
                $stats['sent']++;
            } catch (Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $error = mb_substr($e->getMessage(), 0, 500);
                if ($attempts >= (int) $row['max_attempts']) {
                    $pdo->prepare('UPDATE mail_queue SET attempts = :a, failed_at = NOW(), last_error = :e WHERE id = :id')->execute(['a' => $attempts, 'e' => $error, 'id' => $id]);
                    $this->logger->error('Email given up on', ['to' => $row['to_email'], 'error' => $error]);
                    $stats['failed']++;
                } else {
                    $pdo->prepare('UPDATE mail_queue SET attempts = :a, next_attempt_at = DATE_ADD(NOW(), INTERVAL :m MINUTE), last_error = :e WHERE id = :id')
                        ->execute(['a' => $attempts, 'm' => self::backoff($attempts), 'e' => $error, 'id' => $id]);
                    $stats['retry']++;
                }
            }
        }

        return $stats;
    }

    /** @return array{pending: int, failed: int} */
    public function counts(): array
    {
        $row = $this->db->pdo()->query('SELECT SUM(sent_at IS NULL AND failed_at IS NULL) AS pending, SUM(failed_at IS NOT NULL) AS failed FROM mail_queue')->fetch();

        return ['pending' => (int) ($row['pending'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }

    /** Puts given-up messages back in the queue for another round. */
    public function retryFailed(): int
    {
        $stmt = $this->db->pdo()->prepare('UPDATE mail_queue SET failed_at = NULL, attempts = 0, next_attempt_at = NOW() WHERE failed_at IS NOT NULL');
        $stmt->execute();

        return $stmt->rowCount();
    }

    public static function backoff(int $attempts): int
    {
        return self::BACKOFF_MINUTES[min(max($attempts, 1), count(self::BACKOFF_MINUTES)) - 1];
    }

    /** @return list<array{filename: string, type: string, content: string}> */
    private static function decodeAttachments(?string $json): array
    {
        $out = [];
        foreach ((array) json_decode((string) $json, true) as $file) {
            if (is_array($file) && isset($file['filename'], $file['type'], $file['data'])) {
                $out[] = ['filename' => (string) $file['filename'], 'type' => (string) $file['type'], 'content' => (string) base64_decode((string) $file['data'], true)];
            }
        }

        return $out;
    }

    /** @param list<array{filename: string, type: string, content: string}> $attachments */
    private function store(string $to, string $subject, string $html, string $text, ?string $replyTo, int $attempts, ?string $error, array $attachments = []): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('
            INSERT INTO mail_queue (to_email, subject, body_html, body_text, attachments, reply_to, attempts, max_attempts, next_attempt_at, last_error)
            VALUES (:to, :subject, :html, :text, :files, :reply, :attempts, :max, DATE_ADD(NOW(), INTERVAL :minutes MINUTE), :error)
        ')->execute([
            'to' => $to, 'subject' => mb_substr($subject, 0, 255), 'html' => $html, 'text' => $text, 'reply' => $replyTo,
            'files' => $attachments === [] ? null : json_encode(array_map(static fn (array $a): array => ['filename' => $a['filename'], 'type' => $a['type'], 'data' => base64_encode($a['content'])], $attachments)),
            'attempts' => $attempts, 'max' => self::MAX_ATTEMPTS,
            'minutes' => $attempts > 0 ? self::backoff($attempts) : 0,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
