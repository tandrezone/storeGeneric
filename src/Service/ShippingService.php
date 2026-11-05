<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Repository\ShippingMethodRepository;

/**
 * Shipping rules: which methods deliver where, what they cost (flat cost,
 * free above an optional subtotal), and validation of the admin form.
 */
final class ShippingService
{
    public function __construct(
        private readonly ShippingMethodRepository $methods,
        private readonly Translator $translator,
    ) {
    }

    /** @return list<array<string, mixed>> enabled methods in checkout order */
    public function activeMethods(): array
    {
        return $this->methods->findActive();
    }

    /**
     * An enabled method that delivers to $country, or null.
     *
     * @return array<string, mixed>|null
     */
    public function available(string $code, string $country, int $weightGrams = 0): ?array
    {
        $method = $this->methods->findByCode($code);

        return $method !== null && $method['is_active']
            && $this->servesCountry($method, $country) && $this->servesWeight($method, $weightGrams) ? $method : null;
    }

    /**
     * Flat cost plus the per-kg charge for $weightGrams, or 0 above the
     * "free over" subtotal.
     *
     * @param array<string, mixed> $method
     */
    public function priceFor(array $method, float $subtotal, int $weightGrams = 0): float
    {
        $freeOver = $method['free_over'] ?? null;
        if ($freeOver !== null && $freeOver !== '' && $subtotal >= (float) $freeOver) {
            return 0.0;
        }

        return round((float) $method['cost'] + (float) ($method['cost_per_kg'] ?? 0) * max(0, $weightGrams) / 1000, 2);
    }

    /** Whether the method is offered for an order of this weight (min/max weight limits). @param array<string, mixed> $method */
    public function servesWeight(array $method, int $weightGrams): bool
    {
        $min = $method['min_weight_grams'] ?? null;
        $max = $method['max_weight_grams'] ?? null;

        return ($min === null || $min === '' || $weightGrams >= (int) $min)
            && ($max === null || $max === '' || $weightGrams <= (int) $max);
    }

    /**
     * @param array<string, mixed> $method
     * @return list<string> upper-cased country names/codes; [] = ships everywhere
     */
    public function countryList(array $method): array
    {
        $tokens = array_map(static fn (string $c) => mb_strtoupper(trim($c)), explode(',', (string) ($method['countries'] ?? '')));

        return array_values(array_filter($tokens, static fn (string $c) => $c !== ''));
    }

    /** @param array<string, mixed> $method */
    public function servesCountry(array $method, string $country): bool
    {
        $list = $this->countryList($method);

        return $list === [] || in_array(mb_strtoupper(trim($country)), $list, true);
    }

    /** @param array<string, mixed> $method "Name (description)", stored on orders */
    public function label(array $method): string
    {
        $description = trim((string) ($method['description'] ?? ''));

        return $description !== '' ? $method['name'] . ' (' . $description . ')' : (string) $method['name'];
    }

    /**
     * Validates the admin form.
     *
     * @param array<string, mixed> $input
     * @return array{0: array{name: string, description: ?string, cost: float, cost_per_kg: float, free_over: ?float, countries: ?string, min_weight_grams: ?int, max_weight_grams: ?int, is_active: int}, 1: list<string>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $cost = str_replace(',', '.', trim((string) ($input['cost'] ?? '0')));
        $freeOver = str_replace(',', '.', trim((string) ($input['free_over'] ?? '')));
        $perKg = str_replace(',', '.', trim((string) ($input['cost_per_kg'] ?? '0'))) ?: '0';
        $minWeight = trim((string) ($input['min_weight_grams'] ?? ''));
        $maxWeight = trim((string) ($input['max_weight_grams'] ?? ''));

        if ($name === '') {
            $errors[] = $this->translator->trans('Name is required.');
        } elseif (mb_strlen($name) > 120) {
            $errors[] = $this->translator->trans('Name must be {max} characters or fewer.', ['max' => 120]);
        }
        if (!is_numeric($cost) || (float) $cost < 0) {
            $errors[] = $this->translator->trans('Cost must be 0 or more.');
        }
        if ($freeOver !== '' && (!is_numeric($freeOver) || (float) $freeOver < 0)) {
            $errors[] = $this->translator->trans('"Free over" must be empty or a positive amount.');
        }

        if (!is_numeric($perKg) || (float) $perKg < 0) {
            $errors[] = $this->translator->trans('The cost per kg must be 0 or more.');
        }
        foreach ([$minWeight, $maxWeight] as $w) {
            if ($w !== '' && (!ctype_digit($w) || (int) $w > 4294967295)) {
                $errors[] = $this->translator->trans('Weight limits must be whole numbers of grams, or empty.');
                break;
            }
        }
        if ($minWeight !== '' && $maxWeight !== '' && ctype_digit($minWeight) && ctype_digit($maxWeight) && (int) $minWeight > (int) $maxWeight) {
            $errors[] = $this->translator->trans('The minimum weight can\'t be above the maximum weight.');
        }

        $countries = implode(', ', array_unique(array_filter(
            array_map('trim', explode(',', (string) ($input['countries'] ?? ''))),
            static fn (string $c) => $c !== ''
        )));

        return [[
            'name'        => $name,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 255) ?: null,
            'cost'        => round((float) $cost, 2),
            'cost_per_kg' => round((float) $perKg, 2),
            'free_over'   => $freeOver === '' ? null : round((float) $freeOver, 2),
            'countries'   => $countries !== '' ? mb_substr($countries, 0, 500) : null,
            'min_weight_grams' => ctype_digit($minWeight) ? (int) $minWeight : null,
            'max_weight_grams' => ctype_digit($maxWeight) ? (int) $maxWeight : null,
            'is_active'   => !empty($input['is_active']) ? 1 : 0,
        ], $errors];
    }
}
