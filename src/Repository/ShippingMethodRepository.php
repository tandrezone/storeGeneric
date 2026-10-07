<?php

declare(strict_types=1);

namespace App\Repository;

/** Shipping options managed in Admin → Shipping. */
final class ShippingMethodRepository extends Repository
{
    /** @return list<array<string, mixed>> all methods in checkout order */
    public function findAll(): array
    {
        return $this->all('SELECT * FROM shipping_methods ORDER BY sort_order ASC, id ASC');
    }

    /** @return list<array<string, mixed>> */
    public function findActive(): array
    {
        return $this->all('SELECT * FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM shipping_methods WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->one('SELECT * FROM shipping_methods WHERE code = :code', ['code' => $code]);
    }

    /** @param array{name: string, description: ?string, cost: float, free_over: ?float, countries: ?string, is_active: int} $data */
    public function create(array $data): int
    {
        $next = (int) $this->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shipping_methods');
        $this->run('
            INSERT INTO shipping_methods (code, name, description, cost, free_over, countries, is_active, sort_order)
            VALUES (:code, :name, :description, :cost, :free_over, :countries, :is_active, :sort_order)
        ', $data + ['code' => $this->uniqueCode($data['name']), 'sort_order' => $next]);

        return $this->lastId();
    }

    /**
     * The code never changes after creation, so existing orders keep matching.
     *
     * @param array{name: string, description: ?string, cost: float, free_over: ?float, countries: ?string, is_active: int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->run('
            UPDATE shipping_methods
            SET name = :name, description = :description, cost = :cost, free_over = :free_over,
                countries = :countries, is_active = :is_active
            WHERE id = :id
        ', $data + ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->run('UPDATE shipping_methods SET is_active = :a WHERE id = :id', ['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM shipping_methods WHERE id = :id', ['id' => $id]);
    }

    /** Swaps a method with its neighbour above ('up') or below ('down'). */
    public function move(int $id, string $direction): void
    {
        $ids = array_map(static fn (array $m): int => (int) $m['id'], $this->findAll());
        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            return;
        }
        $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
        if (!isset($ids[$swap])) {
            return;
        }
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];

        $this->db->transaction(function () use ($ids): void {
            foreach ($ids as $i => $methodId) {
                $this->run('UPDATE shipping_methods SET sort_order = :s WHERE id = :id', ['s' => $i + 1, 'id' => $methodId]);
            }
        });
    }

    private function uniqueCode(string $name): string
    {
        $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'shipping', 0, 16);
        $code = $base;
        for ($i = 2; $this->findByCode($code) !== null; $i++) {
            $code = $base . '-' . $i;
        }

        return $code;
    }
}
