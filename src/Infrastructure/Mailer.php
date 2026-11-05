<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Support\Config;
use App\Support\Paths;
use RuntimeException;

/**
 * Sends HTML + plain-text email. MAIL_TRANSPORT picks how:
 *   smtp  SmtpClient with MAIL_HOST / MAIL_PORT / MAIL_ENCRYPTION / MAIL_USERNAME / MAIL_PASSWORD
 *   mail  PHP's mail() (the server's sendmail)
 *   log   (default) appends the message to var/log/mail-YYYY-MM-DD.log — for development
 */
final class Mailer
{
    public function __construct(
        private readonly Config $config,
        private readonly Paths $paths,
    ) {
    }

    public function transport(): string
    {
        $transport = strtolower($this->config->get('MAIL_TRANSPORT', 'log'));

        return in_array($transport, ['smtp', 'mail', 'log'], true) ? $transport : 'log';
    }

    /**
     * @param list<array{filename: string, type: string, content: string}> $attachments raw file contents
     * @throws RuntimeException when the message can't be handed over
     */
    public function send(string $to, string $subject, string $html, string $text, ?string $replyTo = null, array $attachments = []): void
    {
        [$to, $from, $headers, $body] = $this->compose($to, $subject, $html, $text, $replyTo, $attachments);

        match ($this->transport()) {
            'smtp' => $this->smtp()->send($from, [$to], implode("\r\n", $headers) . "\r\n\r\n" . $body),
            'mail' => $this->viaMail($to, $subject, $headers, $body, $from),
            default => $this->log($to, $subject, $text . ($attachments === [] ? '' : "\n[attachments: " . implode(', ', array_map(static fn (array $a): string => $a['filename'] . ' ' . strlen($a['content']) . ' bytes', $attachments)) . ']')),
        };
    }

    /**
     * The finished message: recipient, sender, header lines and MIME body.
     *
     * @param list<array{filename: string, type: string, content: string}> $attachments
     * @return array{0: string, 1: string, 2: list<string>, 3: string}
     */
    public function compose(string $to, string $subject, string $html, string $text, ?string $replyTo = null, array $attachments = []): array
    {
        $to = $this->address($to);
        $from = $this->address($this->config->get('MAIL_FROM', $this->config->get('STORE_EMAIL', 'store@localhost')));
        $fromName = $this->config->get('MAIL_FROM_NAME', $this->config->get('STORE_NAME', 'Store'));

        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->displayName($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (substr(strrchr($from, '@') ?: '@localhost', 1)) . '>',
            'MIME-Version: 1.0',
            'Content-Type: ' . ($attachments === [] ? 'multipart/alternative' : 'multipart/mixed') . '; boundary="' . $boundary . '"',
        ];
        if ($replyTo !== null && $replyTo !== '') {
            $headers[] = 'Reply-To: <' . $this->address($replyTo) . '>';
        }

        $alternatives = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n");
        if ($attachments === []) {
            $body = "--{$boundary}\r\n" . $alternatives . "--{$boundary}--\r\n";
        } else {
            // multipart/mixed: [ multipart/alternative (text, html) ] + one part per file
            $inner = 'a' . bin2hex(random_bytes(12));
            $body = "--{$boundary}\r\nContent-Type: multipart/alternative; boundary=\"{$inner}\"\r\n\r\n"
                . "--{$inner}\r\n" . str_replace("--{$boundary}\r\n", "--{$inner}\r\n", $alternatives) . "--{$inner}--\r\n";
            foreach ($attachments as $file) {
                $name = $this->fileName($file['filename']);
                $body .= "--{$boundary}\r\n"
                    . 'Content-Type: ' . preg_replace('/[^\w.+\/-]/', '', $file['type']) . "; name=\"{$name}\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                    . chunk_split(base64_encode($file['content']), 76, "\r\n");
            }
            $body .= "--{$boundary}--\r\n";
        }

        return [$to, $from, $headers, $body];
    }

    private function smtp(): SmtpClient
    {
        $host = $this->config->get('MAIL_HOST');
        if ($host === '') {
            throw new RuntimeException('MAIL_HOST is not set.');
        }
        $encryption = strtolower($this->config->get('MAIL_ENCRYPTION', 'tls'));

        return new SmtpClient(
            $host,
            $this->config->int('MAIL_PORT', $encryption === 'ssl' ? 465 : 587),
            in_array($encryption, ['ssl', 'tls'], true) ? $encryption : 'none',
            $this->config->get('MAIL_USERNAME'),
            $this->config->get('MAIL_PASSWORD'),
        );
    }

    /** @param list<string> $headers */
    private function viaMail(string $to, string $subject, array $headers, string $body, string $from): void
    {
        // mail() adds To and Subject itself.
        $extra = array_filter($headers, static fn (string $h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:'));
        if (!mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $extra), '-f' . $from)) {
            throw new RuntimeException('mail() refused the message.');
        }
    }

    private function log(string $to, string $subject, string $text): void
    {
        $entry = sprintf("==== %s\nTo: %s\nSubject: %s\n\n%s\n\n", date('c'), $to, preg_replace('/[\r\n]+/', ' ', $subject), $text);
        if (@file_put_contents($this->paths->var('log/mail-' . date('Y-m-d') . '.log'), $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not write the mail log in var/log/.');
        }
    }

    /** A single address with no header injection possible. */
    private function address(string $email): string
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address: ' . preg_replace('/[\r\n]/', '', $email));
        }

        return $email;
    }

    private function displayName(string $name): string
    {
        $encoded = $this->encodeHeader($name);

        return $encoded === $name ? '"' . addcslashes($name, '"\\') . '"' : $encoded;
    }

    private function fileName(string $name): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name), 0, 80) ?: 'file';
    }

    private function encodeHeader(string $value): string
    {
        $value = trim(preg_replace('/[\r\n]+/', ' ', $value) ?? '');

        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }
}
