<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Repository\SettingRepository;
use App\Support\EuCountries;
use PDO;
use RuntimeException;
use Throwable;

/**
 * EU VAT numbers of business customers, checked against the European
 * Commission's VIES service, and the reverse-charge rule that follows.
 *
 * Reverse charge (no VAT, the buyer accounts for it) applies when VAT is on,
 * the store's own country is set (Settings → Invoices → "Your country"), the
 * VAT number is valid, belongs to another EU state than the store's, and the
 * goods go to that same state. Anything else is charged normally.
 *
 * VIES answers are cached (valid 24 h, invalid 1 h). If VIES or the member
 * state's service is down the check is "unavailable": checkout then asks the
 * customer to try again or leave the number out — it never guesses.
 */
final class VatIdService
{
    private const VALID_TTL = 86400;
    private const INVALID_TTL = 3600;
    private const URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    public function __construct(
        private readonly Database $db,
        private readonly HttpClient $http,
        private readonly SettingRepository $settings,
        private readonly TaxSettings $tax,
        private readonly Translator $translator,
    ) {
    }

    /** The store's own country (ISO code) from Settings, or null when unset / not an EU state. */
    public function sellerCountry(): ?string
    {
        $value = (string) $this->settings->get('company_country', '');

        return $value === '' ? null : EuCountries::code($value);
    }

    /** Reverse charge can only happen when VAT is charged and the store's country is known. */
    public function enabled(): bool
    {
        return $this->tax->enabled() && $this->sellerCountry() !== null;
    }

    /**
     * "pt 123.456-789" → ['PT', '123456789', 'PT123456789'], or null when it can't be a VAT number of an EU state.
     *
     * @return array{0: string, 1: string, 2: string}|null country (ISO), number, normalised id
     */
    public static function parse(string $raw): ?array
    {
        $id = strtoupper((string) preg_replace('/[\s.\-\/]/', '', $raw));
        if (preg_match('/^([A-Z]{2})([A-Z0-9]{2,12})$/', $id, $m) !== 1) {
            return null;
        }
        $country = EuCountries::fromVatPrefix($m[1]);

        return $country === null ? null : [$country, $m[2], $m[1] . $m[2]];
    }

    /**
     * Looks the number up.
     *
     * @return array{status: 'valid'|'invalid'|'unavailable', id: string, country: string, name: ?string, address: ?string}
     */
    public function check(string $raw): array
    {
        $parsed = self::parse($raw);
        if ($parsed === null) {
            return ['status' => 'invalid', 'id' => trim($raw), 'country' => '', 'name' => null, 'address' => null];
        }
        [$country, $number, $id] = $parsed;

        $cached = $this->cached($id);
        if ($cached !== null) {
            return $cached + ['id' => $id, 'country' => $country];
        }

        try {
            $response = $this->http->request('GET', sprintf(self::URL, EuCountries::vatPrefix($country), rawurlencode($number)), ['Accept: application/json'], null, false, 12);
            $body = $response['body'];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'id' => $id, 'country' => $country, 'name' => null, 'address' => null];
        }

        $name = $this->text($body['name'] ?? null);
        $address = $this->text($body['address'] ?? null);
        if (($body['isValid'] ?? false) === true) {
            $this->store($id, 'valid', $name, $address);

            return ['status' => 'valid', 'id' => $id, 'country' => $country, 'name' => $name, 'address' => $address];
        }
        // "INVALID" / "INVALID_INPUT" are real answers; anything else (MS_UNAVAILABLE, TIMEOUT, …) means "couldn't check".
        if (in_array($body['userError'] ?? '', ['INVALID', 'INVALID_INPUT'], true)) {
            $this->store($id, 'invalid', null, null);

            return ['status' => 'invalid', 'id' => $id, 'country' => $country, 'name' => null, 'address' => null];
        }

        return ['status' => 'unavailable', 'id' => $id, 'country' => $country, 'name' => null, 'address' => null];
    }

    /**
     * Decides what a checkout does with the number the customer typed.
     *
     * @return array{vat_id: string, reverse_charge: bool, message: ?string, name: ?string}
     *         message: why it can't be used (shown to the customer) — null when fine; for a valid number from the
     *         store's own country or a non-EU delivery it is a plain note and reverse_charge stays false
     * @throws RuntimeException when the number is invalid or couldn't be checked
     */
    public function forCheckout(string $raw, string $shipCountry): array
    {
        $raw = trim($raw);
        if ($raw === '' || !$this->enabled()) {
            return ['vat_id' => '', 'reverse_charge' => false, 'message' => null, 'name' => null];
        }
        $result = $this->check($raw);
        if ($result['status'] === 'unavailable') {
            throw new RuntimeException($this->translator->trans('We couldn\'t check the VAT number right now. Please try again in a moment, or leave it out.'));
        }
        if ($result['status'] === 'invalid') {
            throw new RuntimeException($this->translator->trans('That VAT number is not valid in the EU VAT register (VIES).'));
        }

        $destination = EuCountries::code($shipCountry);
        $reverse = $result['country'] !== $this->sellerCountry() && $destination === $result['country'];

        return [
            'vat_id'         => $result['id'],
            'reverse_charge' => $reverse,
            'name'           => $result['name'],
            'message'        => $reverse ? null : $this->translator->trans('VAT number accepted, but VAT is still charged: reverse charge only applies to businesses in another EU country, with the goods delivered there.'),
        ];
    }

    /** @return array{status: 'valid'|'invalid', name: ?string, address: ?string}|null */
    private function cached(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, name, address, checked_at FROM vat_checks WHERE vat_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $ttl = $row['status'] === 'valid' ? self::VALID_TTL : self::INVALID_TTL;

        return strtotime((string) $row['checked_at']) + $ttl > time()
            ? ['status' => $row['status'], 'name' => $row['name'], 'address' => $row['address']]
            : null;
    }

    private function store(string $id, string $status, ?string $name, ?string $address): void
    {
        $this->db->pdo()->prepare('
            INSERT INTO vat_checks (vat_id, status, name, address, checked_at) VALUES (:id, :s, :n, :a, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), name = VALUES(name), address = VALUES(address), checked_at = VALUES(checked_at)
        ')->execute(['id' => $id, 's' => $status, 'n' => $name, 'a' => $address]);
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' || $text === '---' ? null : mb_substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, 500);
    }
}
