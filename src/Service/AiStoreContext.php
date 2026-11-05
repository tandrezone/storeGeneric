<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Payment\PaymentRegistry;
use App\Repository\ProductRepository;
use App\Repository\ShippingMethodRepository;

/**
 * Plain-text facts about the store for AI prompts (magic edit of pages and
 * newsletters): name, contact, shipping, payment methods and the catalog.
 */
final class AiStoreContext
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ShippingMethodRepository $shipping,
        private readonly PaymentRegistry $payments,
        private readonly StoreSettings $store,
        private readonly SiteUrl $site,
        private readonly Translator $translator,
    ) {
    }

    /** "Português (pt)" for a locale, or the store's language when $locale is ''. */
    public function languageName(string $locale = ''): string
    {
        $code = $this->translator->normalize($locale !== '' ? $locale : $this->store->language()) ?? 'en';

        return ($this->translator->languages()[$code] ?? $code) . ' (' . $code . ')';
    }

    public function facts(): string
    {
        $currency = $this->store->currency();
        $lines = [
            '- Name: ' . $this->store->name(),
            '- Website: ' . $this->site->base(),
            '- Customer email: ' . $this->store->email(),
            '- Currency: ' . $currency,
        ];

        $methods = [];
        foreach ($this->shipping->findActive() as $m) {
            $text = $m['name'] . ': ' . $this->money($m['cost']);
            if ((float) ($m['cost_per_kg'] ?? 0) > 0) {
                $text .= ' + ' . $this->money($m['cost_per_kg']) . ' per kg';
            }
            if ($m['free_over'] !== null) {
                $text .= ', free from ' . $this->money($m['free_over']);
            }
            if (!empty($m['description'])) {
                $text .= ' (' . $m['description'] . ')';
            }
            if (!empty($m['countries'])) {
                $text .= ' — ships to: ' . $m['countries'];
            }
            $methods[] = $text;
        }
        $lines[] = '- Shipping: ' . ($methods === [] ? 'not configured' : implode('; ', $methods));

        $payments = array_map(static fn ($m) => $m->label(), array_values($this->payments->enabled()));
        $lines[] = '- Payment methods: ' . ($payments === [] ? 'not configured' : implode(', ', $payments));

        return implode("\n", $lines);
    }

    /**
     * One line per visible product: category, name, price (and sale), short
     * description; with $newestFirst also the date added and its link.
     */
    public function catalog(int $limit = 80, bool $newestFirst = false): string
    {
        $rows = $this->products->catalogSummary($limit, $newestFirst);
        if ($rows === []) {
            return '(no products published yet)';
        }

        $lines = [];
        foreach ($rows as $row) {
            $line = '- [' . $row['category'] . '] ' . $row['name'];
            if ($row['from_price'] !== null) {
                $line .= ' — from ' . $this->money($row['from_price']);
                if ($row['regular_price'] !== null && (float) $row['from_price'] < (float) $row['regular_price']) {
                    $line .= ' (ON SALE, was ' . $this->money($row['regular_price']) . ')';
                }
            }
            if ($newestFirst) {
                $line .= ' — added ' . substr((string) $row['created_at'], 0, 10) . ' — ' . $this->site->absolute('/product/' . (int) $row['id']);
            }
            $lines[] = $line . ': ' . mb_substr(trim((string) $row['short_description']), 0, 200);
        }

        return implode("\n", $lines);
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2) . ' ' . $this->store->currency();
    }
}
