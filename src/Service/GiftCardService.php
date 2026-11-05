<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Repository\GiftCardRepository;
use App\Repository\OrderRepository;
use App\Support\Money;
use RuntimeException;

/**
 * Gift cards: issuing, validating, spending at checkout and giving the money
 * back. At checkout the card pays as much of the order as its balance covers
 * and the order's `total` becomes what is left for the payment method.
 *
 * Spending (claim()) runs inside the checkout transaction with the card row
 * locked, so two checkouts can't spend the same money. An order holds its
 * amount while orders.gift_card_counted = 1; cancelling, expiring or
 * refunding it flips that flag and credits the card again — once, however
 * often those paths run (release() is idempotent), mirroring discount codes.
 */
final class GiftCardService
{
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I
    public const MAX_AMOUNT = 10000.0;

    public function __construct(
        private readonly GiftCardRepository $cards,
        private readonly OrderRepository $orders,
        private readonly StoreSettings $store,
        private readonly Translator $translator,
    ) {
    }

    /** "  gc-ab12 cd " → "GC-AB12CD" */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    /** A fresh code like GC-7KQ4-M2XD-9PWT (collisions are re-rolled). */
    public function generateCode(): string
    {
        do {
            $groups = [];
            for ($g = 0; $g < 3; $g++) {
                $part = '';
                for ($i = 0; $i < 4; $i++) {
                    $part .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
                }
                $groups[] = $part;
            }
            $code = 'GC-' . implode('-', $groups);
        } while ($this->cards->findByCode($code) !== null);

        return $code;
    }

    /**
     * @return array<string, mixed> the card
     * @throws RuntimeException with a message safe to show the customer
     */
    public function usable(string $code): array
    {
        $card = $this->cards->findByCode(self::normalize($code));

        return $this->check($card);
    }

    /** The part of $payable this card can cover. @param array<string, mixed> $card */
    public function amountFor(array $card, float $payable): float
    {
        return round(max(0.0, min((float) $card['balance'], $payable)), 2);
    }

    /**
     * Spends from the card for an order being placed (call inside the order's transaction).
     *
     * @return array{card: array<string, mixed>, amount: float}
     * @throws RuntimeException
     */
    public function claim(string $code, float $payable): array
    {
        $card = $this->check($this->cards->lockByCode(self::normalize($code)));
        $amount = $this->amountFor($card, $payable);
        if ($amount > 0.0 && !$this->cards->spend((int) $card['id'], $amount)) {
            throw new RuntimeException($this->translator->trans('This gift card doesn\'t have enough balance left.'));
        }

        return ['card' => $card, 'amount' => $amount];
    }

    /** Writes the ledger line once the order exists. */
    public function recordRedemption(int $cardId, int $orderId, float $amount, string $orderNumber): void
    {
        if ($amount > 0.0) {
            $this->cards->addTransaction($cardId, 'redeem', -$amount, $orderId, $orderNumber);
        }
    }

    /** Gives the order's gift-card amount back to the card, once. @param array<string, mixed> $order */
    public function release(array $order): void
    {
        $amount = (float) ($order['gift_card_amount'] ?? 0);
        $code = (string) ($order['gift_card_code'] ?? '');
        if ($amount <= 0.0 || $code === '' || !$this->orders->releaseGiftCardUse((int) $order['id'])) {
            return;
        }
        $card = $this->cards->lockByCode($code);
        if ($card !== null) {
            $this->cards->credit((int) $card['id'], $amount);
            $this->cards->addTransaction((int) $card['id'], 'restore', $amount, (int) $order['id'], (string) $order['order_number']);
        }
    }

    /** A released order that gets paid after all takes its amount again (as far as the card still has it). @param array<string, mixed> $order */
    public function reclaim(array $order): void
    {
        $amount = (float) ($order['gift_card_amount'] ?? 0);
        $code = (string) ($order['gift_card_code'] ?? '');
        if ($amount <= 0.0 || $code === '' || ($card = $this->cards->lockByCode($code)) === null || !$this->orders->claimGiftCardUse((int) $order['id'])) {
            return;
        }
        $take = min($amount, (float) $card['balance']);
        if ($take > 0.0 && $this->cards->spend((int) $card['id'], $take)) {
            $this->cards->addTransaction((int) $card['id'], 'redeem', -$take, (int) $order['id'], (string) $order['order_number'] . ' (paid after release)');
        }
    }

    /**
     * Issues a card.
     *
     * @return array<string, mixed> the new card
     * @throws RuntimeException
     */
    public function issue(float $amount, ?string $code, ?string $expires, ?string $recipientEmail, ?string $message, ?string $by): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0.0 || $amount > self::MAX_AMOUNT) {
            throw new RuntimeException($this->translator->trans('The amount must be above 0 and at most {max}.', ['max' => Money::format(self::MAX_AMOUNT, $this->store->currency(), $this->translator->intlLocale())]));
        }
        $code = $code !== null && trim($code) !== '' ? self::normalize($code) : $this->generateCode();
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{3,39}$/', $code) !== 1) {
            throw new RuntimeException($this->translator->trans('A code is 4-40 characters: letters, digits and hyphens.'));
        }
        if ($this->cards->findByCode($code) !== null) {
            throw new RuntimeException($this->translator->trans('A gift card with that code already exists.'));
        }
        if ($recipientEmail !== null && $recipientEmail !== '' && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException($this->translator->trans('Please enter a valid email address.'));
        }
        $expiresAt = null;
        if ($expires !== null && trim($expires) !== '') {
            $ts = strtotime(str_replace('T', ' ', trim($expires)));
            if ($ts === false || $ts <= time()) {
                throw new RuntimeException($this->translator->trans('The expiry date must be in the future.'));
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        $id = $this->cards->create([
            'code' => $code, 'amount' => $amount, 'expires_at' => $expiresAt,
            'recipient_email' => $recipientEmail !== null && $recipientEmail !== '' ? mb_strtolower($recipientEmail) : null,
            'message' => $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 500) : null,
            'created_by' => $by,
        ]);
        $this->cards->addTransaction($id, 'issue', $amount, null, $by !== null ? 'by ' . $by : null);

        return $this->cards->find($id) ?? throw new RuntimeException('Gift card vanished.');
    }

    /** Staff top-up (positive) or correction (negative). @throws RuntimeException */
    public function adjust(int $cardId, float $delta, ?string $note, ?string $by): void
    {
        $delta = round($delta, 2);
        if ($delta == 0.0 || abs($delta) > self::MAX_AMOUNT) {
            throw new RuntimeException($this->translator->trans('Enter an amount other than 0.'));
        }
        if (!$this->cards->adjust($cardId, $delta)) {
            throw new RuntimeException($this->translator->trans('The balance can\'t go below 0.'));
        }
        $this->cards->addTransaction($cardId, 'adjust', $delta, null, trim(($note ?? '') . ($by !== null ? ' (' . $by . ')' : '')) ?: null);
    }

    /**
     * @param array<string, mixed>|null $card
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private function check(?array $card): array
    {
        if ($card === null) {
            throw new RuntimeException($this->translator->trans('That gift card code doesn\'t exist.'));
        }
        if (!(int) $card['is_active']) {
            throw new RuntimeException($this->translator->trans('This gift card is not active.'));
        }
        if ($card['expires_at'] !== null && strtotime((string) $card['expires_at']) <= time()) {
            throw new RuntimeException($this->translator->trans('This gift card has expired.'));
        }
        if ((float) $card['balance'] <= 0.0) {
            throw new RuntimeException($this->translator->trans('This gift card has no balance left.'));
        }

        return $card;
    }
}
