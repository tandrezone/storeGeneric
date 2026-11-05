<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Session;
use App\I18n\Translator;
use App\Infrastructure\EcbRates;
use App\Repository\SettingRepository;
use App\Support\Money;
use RuntimeException;

/**
 * Approximate prices in other currencies — display only. Everything is priced,
 * paid and refunded in the store currency (STORE_CURRENCY); visitors may pick a
 * currency to see "≈ $13.50" next to catalogue prices, converted at the rates
 * set in Admin → Settings → Currencies (typed by hand, or refreshed from the
 * European Central Bank when the store currency is EUR). Cart, checkout, orders
 * and emails never show converted amounts.
 */
final class CurrencyService
{
    private const SESSION_KEY = 'display_currency';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly StoreSettings $store,
        private readonly Session $session,
        private readonly Translator $translator,
    ) {
    }

    public function base(): string
    {
        return $this->store->currency();
    }

    /**
     * "USD=1.08, GBP=0.86" → ['USD' => 1.08, 'GBP' => 0.86] (1 store-currency unit = rate units).
     *
     * @return array{0: array<string, float>, 1: list<string>} rates, problems
     */
    public static function parseRates(string $text, string $base): array
    {
        $rates = [];
        $problems = [];
        // A comma separates entries only before the next "CODE="; otherwise it is a decimal comma ("USD=1,10").
        foreach (preg_split('/[;\n]+|,(?=\s*[A-Za-z]{3}\s*=)/', $text) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z]{3})\s*=\s*([0-9]+(?:[.,][0-9]+)?)$/', $part, $m) !== 1 || (float) str_replace(',', '.', $m[2]) <= 0) {
                $problems[] = $part;
                continue;
            }
            $code = strtoupper($m[1]);
            if ($code === strtoupper($base)) {
                $problems[] = $part;
                continue;
            }
            $rates[$code] = (float) str_replace(',', '.', $m[2]);
        }

        return [$rates, $problems];
    }

    /** @return array<string, float> code => units per 1 store-currency unit */
    public function rates(): array
    {
        return self::parseRates((string) $this->settings->get('currency_rates', ''), $this->base())[0];
    }

    public function ratesText(): string
    {
        return (string) $this->settings->get('currency_rates', '');
    }

    public function autoUpdate(): bool
    {
        return $this->settings->get('currency_rates_auto', '0') === '1';
    }

    public function updatedOn(): ?string
    {
        return $this->settings->get('currency_rates_date');
    }

    /** @return list<string> currencies visitors can choose */
    public function available(): array
    {
        return array_keys($this->rates());
    }

    /** The currency the visitor chose, if it is still offered. */
    public function chosen(): ?string
    {
        $code = $this->session->get(self::SESSION_KEY);

        return is_string($code) && isset($this->rates()[$code]) ? $code : null;
    }

    public function choose(?string $code): void
    {
        $code = $code !== null ? strtoupper(trim($code)) : null;
        if ($code !== null && isset($this->rates()[$code])) {
            $this->session->set(self::SESSION_KEY, $code);
        } else {
            $this->session->remove(self::SESSION_KEY);
        }
    }

    public function convert(float $amount, string $code): float
    {
        $rate = $this->rates()[strtoupper($code)] ?? null;
        if ($rate === null) {
            throw new RuntimeException('No rate for ' . $code);
        }

        return round($amount * $rate, Money::exponent($code));
    }

    /** "≈ $13.50" for the visitor's chosen currency, or '' when none is chosen. */
    public function approx(float|int|string|null $amount): string
    {
        $code = $this->chosen();
        if ($code === null || $amount === null || $amount === '') {
            return '';
        }
        // ICU uses narrow / no-break spaces; plain ones wrap better in running text.
        return '≈ ' . str_replace(["\u{202F}", "\u{00A0}"], ' ', Money::format($this->convert((float) $amount, $code), $code, $this->translator->intlLocale()));
    }

    /**
     * Saves hand-typed rates.
     *
     * @return list<string> lines that could not be read (nothing is saved when there are any)
     */
    public function saveRates(string $text, bool $auto): array
    {
        [$rates, $problems] = self::parseRates($text, $this->base());
        if ($problems !== []) {
            return $problems;
        }
        $this->settings->set('currency_rates', $rates === [] ? null : $this->format($rates));
        $this->settings->set('currency_rates_auto', $auto ? '1' : '0');
        if ($rates === []) {
            $this->settings->set('currency_rates_date', null);
        }

        return [];
    }

    /**
     * Replaces the rate of every listed currency with today's ECB rate (the list itself stays yours).
     *
     * @return int currencies updated
     * @throws RuntimeException when the store currency isn't EUR, or the feed fails / lacks a listed currency
     */
    public function updateFromEcb(EcbRates $ecb): int
    {
        if ($this->base() !== 'EUR') {
            throw new RuntimeException($this->translator->trans('Automatic rates are in euros: set the store currency to EUR, or type the rates by hand.'));
        }
        $current = $this->rates();
        if ($current === []) {
            throw new RuntimeException($this->translator->trans('Add the currencies you want to show first, e.g. USD=1.10, GBP=0.85.'));
        }
        $feed = $ecb->fetch();
        $updated = [];
        foreach (array_keys($current) as $code) {
            $updated[$code] = $feed['rates'][$code] ?? $current[$code]; // a currency the ECB doesn't publish keeps its typed rate
        }
        $this->settings->set('currency_rates', $this->format($updated));
        $this->settings->set('currency_rates_date', $feed['date']);

        return count(array_intersect_key($current, $feed['rates']));
    }

    /** @param array<string, float> $rates */
    private function format(array $rates): string
    {
        return implode(', ', array_map(static fn (string $c, float $r): string => $c . '=' . rtrim(rtrim(number_format($r, 6, '.', ''), '0'), '.'), array_keys($rates), $rates));
    }
}
