<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Saved addresses of a customer account (same fields as the checkout form).
 * Every query is scoped to the customer, so ids from a form can't reach
 * someone else's address.
 */
final class CustomerAddressRepository extends Repository
{
    public const FIELDS = ['label', 'name', 'phone', 'address1', 'address2', 'city', 'state', 'postal_code', 'country'];

    /** @return list<array<string, mixed>> default first */
    public function forCustomer(int $customerId): array
    {
        return $this->all(
            'SELECT * FROM customer_addresses WHERE customer_id = :c ORDER BY is_default DESC, id ASC',
            ['c' => $customerId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $customerId, int $id): ?array
    {
        return $this->one('SELECT * FROM customer_addresses WHERE id = :id AND customer_id = :c', ['id' => $id, 'c' => $customerId]);
    }

    /** @return array<string, mixed>|null the default address (or the oldest one) */
    public function defaultFor(int $customerId): ?array
    {
        return $this->one(
            'SELECT * FROM customer_addresses WHERE customer_id = :c ORDER BY is_default DESC, id ASC LIMIT 1',
            ['c' => $customerId]
        );
    }

    public function count(int $customerId): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :c', ['c' => $customerId]);
    }

    /**
     * @param array<string, string|null> $address FIELDS
     * @return int the new id; the first address becomes the default
     */
    public function create(int $customerId, array $address, bool $default = false): int
    {
        $values = $this->values($address);
        $this->run('
            INSERT INTO customer_addresses (customer_id, label, name, phone, address1, address2, city, state, postal_code, country)
            VALUES (:customer_id, :label, :name, :phone, :address1, :address2, :city, :state, :postal_code, :country)
        ', ['customer_id' => $customerId] + $values);
        $id = $this->lastId();

        if ($default || $this->count($customerId) === 1) {
            $this->setDefault($customerId, $id);
        }

        return $id;
    }

    /** @param array<string, string|null> $address FIELDS */
    public function update(int $customerId, int $id, array $address): bool
    {
        $values = $this->values($address);

        return $this->run('
            UPDATE customer_addresses
            SET label = :label, name = :name, phone = :phone, address1 = :address1, address2 = :address2,
                city = :city, state = :state, postal_code = :postal_code, country = :country
            WHERE id = :id AND customer_id = :customer_id
        ', ['id' => $id, 'customer_id' => $customerId] + $values)->rowCount() > 0
            || $this->find($customerId, $id) !== null;
    }

    /** Deletes an address; if it was the default, the oldest remaining one takes over. */
    public function delete(int $customerId, int $id): bool
    {
        $deleted = $this->run('DELETE FROM customer_addresses WHERE id = :id AND customer_id = :c', ['id' => $id, 'c' => $customerId])->rowCount() === 1;
        if ($deleted && !(bool) $this->value('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :c AND is_default = 1', ['c' => $customerId])) {
            $next = $this->value('SELECT id FROM customer_addresses WHERE customer_id = :c ORDER BY id ASC LIMIT 1', ['c' => $customerId]);
            if ($next !== false) {
                $this->setDefault($customerId, (int) $next);
            }
        }

        return $deleted;
    }

    public function setDefault(int $customerId, int $id): bool
    {
        if ($this->find($customerId, $id) === null) {
            return false;
        }
        $this->run(
            'UPDATE customer_addresses SET is_default = (id = :id) WHERE customer_id = :c',
            ['id' => $id, 'c' => $customerId]
        );

        return true;
    }

    /**
     * Whether the customer already saved this exact address (case-insensitive),
     * so "save this address" at checkout doesn't add duplicates.
     *
     * @param array<string, string|null> $address
     */
    public function exists(int $customerId, array $address): bool
    {
        $values = $this->values($address);

        return (bool) $this->value('
            SELECT COUNT(*) FROM customer_addresses
            WHERE customer_id = :c AND name = :name AND address1 = :address1 AND COALESCE(address2, \'\') = :address2
              AND city = :city AND postal_code = :postal_code AND country = :country
        ', [
            'c' => $customerId, 'name' => $values['name'], 'address1' => $values['address1'], 'address2' => (string) $values['address2'],
            'city' => $values['city'], 'postal_code' => $values['postal_code'], 'country' => $values['country'],
        ]);
    }

    /**
     * @param array<string, string|null> $address
     * @return array<string, string|null> column => value, empty optional fields as NULL
     */
    private function values(array $address): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            $values[$field] = $value === '' && in_array($field, ['label', 'phone', 'address2', 'state'], true) ? null : $value;
        }

        return $values;
    }
}
