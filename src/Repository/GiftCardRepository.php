<?php

declare(strict_types=1);

namespace App\Repository;

/** Gift cards (gift_cards) and their ledger (gift_card_transactions). Rules live in GiftCardService. */
final class GiftCardRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->one('SELECT * FROM gift_cards WHERE code = :c', ['c' => $code]);
    }

    /** Locks the card until the surrounding transaction ends. @return array<string, mixed>|null */
    public function lockByCode(string $code): ?array
    {
        return $this->one('SELECT * FROM gift_cards WHERE code = :c FOR UPDATE', ['c' => $code]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM gift_cards WHERE id = :id', ['id' => $id]);
    }

    /** @param array{code: string, amount: float, expires_at: ?string, recipient_email: ?string, message: ?string, created_by: ?string} $card */
    public function create(array $card): int
    {
        $this->run('
            INSERT INTO gift_cards (code, initial_amount, balance, expires_at, recipient_email, message, created_by)
            VALUES (:code, :amount, :amount2, :expires_at, :recipient_email, :message, :created_by)
        ', ['code' => $card['code'], 'amount' => $card['amount'], 'amount2' => $card['amount'], 'expires_at' => $card['expires_at'], 'recipient_email' => $card['recipient_email'], 'message' => $card['message'], 'created_by' => $card['created_by']]);

        return $this->lastId();
    }

    public function setActive(int $id, bool $active): void
    {
        $this->run('UPDATE gift_cards SET is_active = :a WHERE id = :id', ['a' => $active ? 1 : 0, 'id' => $id]);
    }

    /** Takes $amount off the balance; false (nothing changed) when the balance is lower. */
    public function spend(int $id, float $amount): bool
    {
        return $this->run('UPDATE gift_cards SET balance = balance - :a WHERE id = :id AND balance >= :a2', ['a' => $amount, 'a2' => $amount, 'id' => $id])->rowCount() === 1;
    }

    /** Adds to the balance, never above what the card was issued for plus top-ups ($ceiling). */
    public function credit(int $id, float $amount): void
    {
        $this->run('UPDATE gift_cards SET balance = balance + :a WHERE id = :id', ['a' => $amount, 'id' => $id]);
    }

    /** Staff top-up or correction: keeps initial_amount as "total ever loaded" when adding. */
    public function adjust(int $id, float $delta): bool
    {
        if ($delta >= 0) {
            $this->run('UPDATE gift_cards SET balance = balance + :d, initial_amount = initial_amount + :d2 WHERE id = :id', ['d' => $delta, 'd2' => $delta, 'id' => $id]);

            return true;
        }

        return $this->run('UPDATE gift_cards SET balance = balance + :d WHERE id = :id AND balance + :d2 >= 0', ['d' => $delta, 'd2' => $delta, 'id' => $id])->rowCount() === 1;
    }

    public function addTransaction(int $cardId, string $type, float $amount, ?int $orderId = null, ?string $note = null): void
    {
        $this->run('INSERT INTO gift_card_transactions (gift_card_id, order_id, type, amount, note) VALUES (:c, :o, :t, :a, :n)', ['c' => $cardId, 'o' => $orderId, 't' => $type, 'a' => $amount, 'n' => $note]);
    }

    /**
     * What $orderId currently holds on the card according to the ledger
     * (redeemed minus restored), or null when the ledger has no line for it.
     */
    public function heldByOrder(int $cardId, int $orderId): ?float
    {
        $row = $this->one("SELECT COUNT(*) AS n, COALESCE(-SUM(amount), 0) AS held FROM gift_card_transactions WHERE gift_card_id = :c AND order_id = :o AND type IN ('redeem', 'restore')", ['c' => $cardId, 'o' => $orderId]) ?? [];

        return (int) ($row['n'] ?? 0) > 0 ? round((float) $row['held'], 2) : null;
    }

    /** @return list<array<string, mixed>> newest first, with the order number */
    public function transactions(int $cardId, int $limit = 100): array
    {
        return $this->all('
            SELECT t.*, o.order_number FROM gift_card_transactions t LEFT JOIN orders o ON o.id = t.order_id
            WHERE t.gift_card_id = :c ORDER BY t.id DESC LIMIT ' . max(1, $limit), ['c' => $cardId]);
    }

    /** @return list<array<string, mixed>> newest first */
    public function page(int $limit, int $offset = 0): array
    {
        return $this->all('SELECT * FROM gift_cards ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    }

    public function count(): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM gift_cards');
    }

    /** @return array{outstanding: float, cards: int} money still held on active cards that haven't expired */
    public function liability(): array
    {
        $row = $this->one('SELECT COALESCE(SUM(balance), 0) AS total, COUNT(*) AS n FROM gift_cards WHERE is_active = 1 AND balance > 0 AND (expires_at IS NULL OR expires_at > NOW())') ?? [];

        return ['outstanding' => round((float) ($row['total'] ?? 0), 2), 'cards' => (int) ($row['n'] ?? 0)];
    }
}
