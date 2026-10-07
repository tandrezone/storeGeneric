<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingRepository;
use RuntimeException;

/**
 * VAT settings from Admin → Settings → Tax (settings table):
 *
 *   tax_enabled         '1' to charge VAT (default off)
 *   tax_rate            default rate in %, e.g. '23'
 *   tax_prices_include  '1' = prices are entered including VAT (default), '0' = VAT is added on top
 *   tax_country_rates   per-country overrides, "PT=23, ES=21, Germany=19"
 *   tax_shipping        '1' = shipping is taxed too (default)
 */
final class TaxSettings
{
    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->get('tax_enabled', '0') === '1';
    }

    public function defaultRate(): float
    {
        return $this->clampRate((float) $this->settings->get('tax_rate', '0'));
    }

    public function pricesIncludeTax(): bool
    {
        return $this->settings->get('tax_prices_include', '1') === '1';
    }

    public function shippingTaxed(): bool
    {
        return $this->settings->get('tax_shipping', '1') === '1';
    }

    /** @return array<string, float> upper-cased country name/code => rate */
    public function countryRates(): array
    {
        return $this->parseCountryRates((string) $this->settings->get('tax_country_rates', ''))[0];
    }

    /**
     * Rate for the country the customer typed (matched like shipping
     * countries: trimmed, not case-sensitive), else the default rate.
     * 0 when VAT is switched off.
     */
    public function rateFor(string $country): float
    {
        if (!$this->enabled()) {
            return 0.0;
        }

        return $this->countryRates()[mb_strtoupper(trim($country))] ?? $this->defaultRate();
    }

    /** @return array{enabled: bool, rate: float, prices_include: bool, shipping: bool, country_rates: string} */
    public function toArray(): array
    {
        return [
            'enabled'        => $this->enabled(),
            'rate'           => $this->defaultRate(),
            'prices_include' => $this->pricesIncludeTax(),
            'shipping'       => $this->shippingTaxed(),
            'country_rates'  => $this->formatCountryRates($this->countryRates()),
        ];
    }

    /**
     * Validates and stores the Tax form.
     *
     * @param array<string, mixed> $input
     * @throws RuntimeException with a message for the admin
     */
    public function save(array $input): void
    {
        $rate = str_replace(',', '.', trim((string) ($input['tax_rate'] ?? '0')));
        if ($rate === '') {
            $rate = '0';
        }
        if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            throw new RuntimeException('The VAT rate must be a percentage between 0 and 100.');
        }
        [$countries, $errors] = $this->parseCountryRates((string) ($input['tax_country_rates'] ?? ''));
        if ($errors !== []) {
            throw new RuntimeException('Country rates must look like "PT=23, ES=21" (0-100). Not understood: ' . implode(', ', $errors) . '.');
        }

        $this->settings->set('tax_enabled', !empty($input['tax_enabled']) ? '1' : '0');
        $this->settings->set('tax_rate', (string) round((float) $rate, 2));
        $this->settings->set('tax_prices_include', !empty($input['tax_prices_include']) ? '1' : '0');
        $this->settings->set('tax_shipping', !empty($input['tax_shipping']) ? '1' : '0');
        $this->settings->set('tax_country_rates', $countries === [] ? null : $this->formatCountryRates($countries));
    }

    /**
     * "PT=23, ES=21" (commas, semicolons or new lines between entries; ":" works too).
     *
     * @return array{0: array<string, float>, 1: list<string>} rates and the entries that couldn't be read
     */
    private function parseCountryRates(string $text): array
    {
        $rates = [];
        $errors = [];
        foreach (preg_split('/[,;\r\n]+/', $text) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!preg_match('/^(.+?)\s*[=:]\s*([0-9]+(?:[.,][0-9]+)?)\s*%?$/u', $entry, $m)) {
                $errors[] = $entry;
                continue;
            }
            $rate = (float) str_replace(',', '.', $m[2]);
            $country = mb_strtoupper(trim($m[1]));
            if ($rate > 100 || $country === '' || mb_strlen($country) > 60) {
                $errors[] = $entry;
                continue;
            }
            $rates[$country] = round($rate, 2);
        }

        return [$rates, $errors];
    }

    /** @param array<string, float> $rates */
    private function formatCountryRates(array $rates): string
    {
        $parts = [];
        foreach ($rates as $country => $rate) {
            $parts[] = $country . '=' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
        }

        return implode(', ', $parts);
    }

    private function clampRate(float $rate): float
    {
        return round(max(0.0, min(100.0, $rate)), 2);
    }
}
