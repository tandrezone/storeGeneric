<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\LocaleFormat;
use App\I18n\Translator;
use App\Repository\CouponRepository;
use App\Repository\OrderRepository;
use App\Support\Money;
use DateTimeImmutable;
use RuntimeException;

/**
 * Discount codes: whether a code can be used, what it takes off, and its
 * usage count. A use is counted in the checkout transaction (coupon row
 * locked, so usage_limit holds under concurrent checkouts) and given back
 * at most once if the order is cancelled before it is paid
 * (orders.coupon_counted, like OrderStock's stock_reserved). Call claim(),
 * release() and reclaim() inside a transaction.
 */
final class CouponService
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{1,39}$/';

    public function __construct(
        private readonly CouponRepository $coupons,
        private readonly OrderRepository $orders,
        private readonly StoreSettings $store,
        private readonly Translator $translator,
    ) {
    }

    /** "  summer-10 " → "SUMMER-10" */
    public static function normalize(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $code) ?? '');
    }

    /**
     * The coupon for $code if it can be used on this order now.
     *
     * @return array<string, mixed>
     * @throws RuntimeException with a message safe to show the customer
     */
    public function usable(string $code, float $subtotal, string $email = ''): array
    {
        $code = self::normalize($code);
        $coupon = $code === '' ? null : $this->coupons->findByCode($code);
        if ($coupon === null) {
            throw new RuntimeException($this->translator->trans('The discount code "{code}" doesn\'t exist.', ['code' => $code]));
        }
        $this->check($coupon, $subtotal, $email);

        return $coupon;
    }

    /**
     * Counts a use of the code for an order being placed: locks the coupon,
     * re-checks it and raises used_count. Call inside the checkout transaction.
     *
     * @return array<string, mixed> the coupon
     * @throws RuntimeException with a message safe to show the customer
     */
    public function claim(string $code, float $subtotal, string $email): array
    {
        $code = self::normalize($code);
        $coupon = $this->coupons->lockByCode($code)
            ?? throw new RuntimeException($this->translator->trans('The discount code "{code}" doesn\'t exist.', ['code' => $code]));
        $this->check($coupon, $subtotal, $email);
        $this->coupons->incrementUsed((int) $coupon['id']);

        return $coupon;
    }

    /**
     * What the coupon takes off: an amount off the items subtotal, or the
     * shipping. Never more than the subtotal / shipping.
     *
     * @param array<string, mixed> $coupon
     * @return array{items: float, shipping: float}
     */
    public function discount(array $coupon, float $subtotal, float $shipping): array
    {
        $decimals = min(2, Money::exponent($this->store->currency()));
        $value = max(0.0, (float) $coupon['value']);

        return match ((string) $coupon['type']) {
            'percent'       => ['items' => round($subtotal * min(100.0, $value) / 100, $decimals), 'shipping' => 0.0],
            'fixed'         => ['items' => round(min($value, max(0.0, $subtotal)), $decimals), 'shipping' => 0.0],
            'free_shipping' => ['items' => 0.0, 'shipping' => max(0.0, $shipping)],
            default         => ['items' => 0.0, 'shipping' => 0.0],
        };
    }

    /** @param array<string, mixed> $coupon "10% off", "€5.00 off", "Free shipping" */
    public function describe(array $coupon): string
    {
        $locale = $this->translator->intlLocale();
        $value = (float) $coupon['value'];

        return match ((string) $coupon['type']) {
            'percent'       => $this->translator->trans('{percent}% off', [
                'percent' => LocaleFormat::number($value, $locale, strlen(rtrim(substr(number_format($value, 2, '.', ''), -2), '0'))),
            ]),
            'fixed'         => $this->translator->trans('{amount} off', ['amount' => Money::format($value, $this->store->currency(), $locale)]),
            'free_shipping' => $this->translator->trans('Free shipping'),
            default         => '',
        };
    }

    /**
     * The order no longer counts as a use of its code (cancelled, failed or
     * expired before payment). Safe to call more than once.
     *
     * @param array<string, mixed> $order
     */
    public function release(array $order): void
    {
        $code = (string) ($order['coupon_code'] ?? '');
        if ($code !== '' && $this->orders->releaseCouponUse((int) $order['id'])) {
            $this->coupons->decrementUsed($code);
        }
    }

    /**
     * A released order got paid after all: count its use again (even past
     * the usage limit — the customer has paid with the discount).
     *
     * @param array<string, mixed> $order
     */
    public function reclaim(array $order): void
    {
        $code = (string) ($order['coupon_code'] ?? '');
        if ($code !== '' && ($coupon = $this->coupons->lockByCode($code)) !== null && $this->orders->claimCouponUse((int) $order['id'])) {
            $this->coupons->incrementUsed((int) $coupon['id']);
        }
    }

    /**
     * Validates the Admin → Discounts form.
     *
     * @param array<string, mixed> $input
     * @return array{0: array{code: string, type: string, value: float, min_subtotal: ?float, starts_at: ?string, ends_at: ?string, usage_limit: ?int, per_email_limit: ?int, is_active: int}, 1: list<string>}
     */
    public function validate(array $input, int $exceptId = 0): array
    {
        $errors = [];
        $code = self::normalize((string) ($input['code'] ?? ''));
        $type = (string) ($input['type'] ?? '');
        $value = str_replace(',', '.', trim((string) ($input['value'] ?? '0')));
        $minSubtotal = str_replace(',', '.', trim((string) ($input['min_subtotal'] ?? '')));

        if (!preg_match(self::CODE_PATTERN, $code)) {
            $errors[] = $this->translator->trans('The code must be 2-40 letters, digits, "-" or "_".');
        } elseif ($this->coupons->codeTaken($code, $exceptId)) {
            $errors[] = $this->translator->trans('There is already a discount code "{code}".', ['code' => $code]);
        }
        if (!in_array($type, CouponRepository::TYPES, true)) {
            $errors[] = $this->translator->trans('Choose a discount type.');
        }
        if ($type === 'free_shipping') {
            $value = '0';
        } elseif ($value === '' || !is_numeric($value) || (float) $value <= 0) {
            $errors[] = $this->translator->trans('The discount value must be more than 0.');
        } elseif ($type === 'percent' && (float) $value > 100) {
            $errors[] = $this->translator->trans('A percentage discount can be at most 100.');
        }
        if ($minSubtotal !== '' && (!is_numeric($minSubtotal) || (float) $minSubtotal < 0)) {
            $errors[] = $this->translator->trans('"Minimum subtotal" must be empty or an amount of 0 or more.');
        }

        $startsAt = $this->dateTime((string) ($input['starts_at'] ?? ''), $errors, $this->translator->trans('Start date isn\'t a valid date.'));
        $endsAt = $this->dateTime((string) ($input['ends_at'] ?? ''), $errors, $this->translator->trans('End date isn\'t a valid date.'));
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $errors[] = $this->translator->trans('The end date must be after the start date.');
        }

        $usageLimit = $this->limit(
            $input['usage_limit'] ?? '',
            $errors,
            $this->translator->trans('Usage limit must be empty (unlimited) or a whole number of 1 or more.')
        );
        $perEmailLimit = $this->limit(
            $input['per_email_limit'] ?? '',
            $errors,
            $this->translator->trans('Uses per customer must be empty (unlimited) or a whole number of 1 or more.')
        );

        return [[
            'code'            => $code,
            'type'            => $type,
            'value'           => round((float) $value, 2),
            'min_subtotal'    => $minSubtotal === '' || !is_numeric($minSubtotal) ? null : round((float) $minSubtotal, 2),
            'starts_at'       => $startsAt,
            'ends_at'         => $endsAt,
            'usage_limit'     => $usageLimit,
            'per_email_limit' => $perEmailLimit,
            'is_active'       => !empty($input['is_active']) ? 1 : 0,
        ], $errors];
    }

    /**
     * @param array<string, mixed> $coupon
     * @throws RuntimeException with a message safe to show the customer
     */
    private function check(array $coupon, float $subtotal, string $email): void
    {
        $code = (string) $coupon['code'];
        $now = date('Y-m-d H:i:s');
        if (
            !(int) $coupon['is_active']
            || ($coupon['starts_at'] !== null && $now < (string) $coupon['starts_at'])
            || ($coupon['ends_at'] !== null && $now >= (string) $coupon['ends_at'])
        ) {
            throw new RuntimeException($this->translator->trans('The discount code "{code}" isn\'t valid right now.', ['code' => $code]));
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            throw new RuntimeException($this->translator->trans('The discount code "{code}" has been used up.', ['code' => $code]));
        }
        if ($coupon['min_subtotal'] !== null && $subtotal < (float) $coupon['min_subtotal']) {
            throw new RuntimeException($this->translator->trans('The discount code "{code}" needs a subtotal of at least {amount}.', [
                'code'   => $code,
                'amount' => Money::format((float) $coupon['min_subtotal'], $this->store->currency(), $this->translator->intlLocale()),
            ]));
        }
        if (
            $email !== '' && $coupon['per_email_limit'] !== null
            && $this->coupons->usesByEmail($code, $email) >= (int) $coupon['per_email_limit']
        ) {
            throw new RuntimeException($this->translator->trans('You have already used the discount code "{code}".', ['code' => $code]));
        }
    }

    /** @param list<string> $errors */
    private function dateTime(string $value, array &$errors, string $invalid): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $problems = DateTimeImmutable::getLastErrors(); // false on PHP 8.2+ when there were none
            if ($date !== false && ($problems === false || $problems['warning_count'] + $problems['error_count'] === 0)) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        $errors[] = $invalid;

        return null;
    }

    /** @param list<string> $errors */
    private function limit(mixed $value, array &$errors, string $invalid): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $limit = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000000]]);
        if ($limit === false) {
            $errors[] = $invalid;

            return null;
        }

        return $limit;
    }
}
