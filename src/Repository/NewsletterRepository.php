<?php

declare(strict_types=1);

namespace App\Repository;

/** Newsletter list (newsletter_subscribers) and the campaigns sent to it (newsletter_campaigns). */
final class NewsletterRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT * FROM newsletter_subscribers WHERE email = :e', ['e' => $email]);
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        return $this->one('SELECT * FROM newsletter_subscribers WHERE token = :t', ['t' => $token]);
    }

    public function create(string $email, ?string $locale): string
    {
        $token = bin2hex(random_bytes(16));
        $this->run('INSERT INTO newsletter_subscribers (email, locale, token) VALUES (:e, :l, :t)', ['e' => $email, 'l' => $locale, 't' => $token]);

        return $token;
    }

    public function markConfirmationSent(int $id): void
    {
        $this->run('UPDATE newsletter_subscribers SET confirm_sent_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    /** Back to "waiting for confirmation" (someone who had unsubscribed signs up again). */
    public function reopen(int $id, ?string $locale): void
    {
        $this->run('UPDATE newsletter_subscribers SET confirmed_at = NULL, unsubscribed_at = NULL, locale = :l WHERE id = :id', ['l' => $locale, 'id' => $id]);
    }

    public function confirm(int $id): void
    {
        $this->run('UPDATE newsletter_subscribers SET confirmed_at = COALESCE(confirmed_at, NOW()), unsubscribed_at = NULL WHERE id = :id', ['id' => $id]);
    }

    public function unsubscribe(int $id): void
    {
        $this->run('UPDATE newsletter_subscribers SET unsubscribed_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    /** Removes a person's row entirely (account deletion). */
    public function deleteByEmail(string $email): void
    {
        $this->run('DELETE FROM newsletter_subscribers WHERE email = :e', ['e' => $email]);
    }

    /** @return list<array<string, mixed>> people who confirmed and haven't left */
    public function active(): array
    {
        return $this->all('SELECT * FROM newsletter_subscribers WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL ORDER BY id');
    }

    /** @return array{active: int, pending: int, unsubscribed: int} */
    public function counts(): array
    {
        $row = $this->one('
            SELECT SUM(confirmed_at IS NOT NULL AND unsubscribed_at IS NULL) AS active,
                   SUM(confirmed_at IS NULL AND unsubscribed_at IS NULL) AS pending,
                   SUM(unsubscribed_at IS NOT NULL) AS unsubscribed
            FROM newsletter_subscribers
        ') ?? [];

        return ['active' => (int) ($row['active'] ?? 0), 'pending' => (int) ($row['pending'] ?? 0), 'unsubscribed' => (int) ($row['unsubscribed'] ?? 0)];
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->all('SELECT * FROM newsletter_subscribers ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    public function recordCampaign(string $subject, string $body, int $recipients, ?string $sentBy): void
    {
        $this->run('INSERT INTO newsletter_campaigns (subject, body, recipients, sent_by) VALUES (:s, :b, :r, :u)', ['s' => $subject, 'b' => $body, 'r' => $recipients, 'u' => $sentBy]);
    }

    /** @return list<array<string, mixed>> */
    public function campaigns(int $limit = 20): array
    {
        return $this->all('SELECT * FROM newsletter_campaigns ORDER BY id DESC LIMIT ' . max(1, $limit));
    }
}
