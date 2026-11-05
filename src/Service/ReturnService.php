<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Repository\ReturnRepository;
use App\Repository\SettingRepository;
use RuntimeException;

/**
 * Customer return requests. Eligibility: the order was shipped (or completed),
 * the return window (days since shipping) hasn't passed, and some items are
 * left that no open request covers. Staff move a request along
 * requested → approved | rejected → received → refunded; the customer is
 * emailed on each step except "refunded" (the refund email covers that).
 */
final class ReturnService
{
    public const DEFAULT_WINDOW_DAYS = 14;

    /** @var array<string, string> reason code => label (translated when shown) */
    public const REASONS = [
        'damaged'          => 'Damaged or defective',
        'wrong_item'       => 'Wrong item received',
        'not_as_described' => 'Not as described',
        'changed_mind'     => 'Changed my mind',
        'other'            => 'Other',
    ];

    /** current status => statuses staff may move it to */
    private const NEXT = [
        'requested' => ['approved', 'rejected'],
        'approved'  => ['received', 'rejected'],
        'received'  => ['refunded'],
        'rejected'  => [],
        'refunded'  => [],
    ];

    public function __construct(
        private readonly ReturnRepository $returns,
        private readonly SettingRepository $settings,
        private readonly ReturnNotifier $notifier,
        private readonly Translator $translator,
    ) {
    }

    public function windowDays(): int
    {
        $value = $this->settings->get('return_window_days');

        return $value === null ? self::DEFAULT_WINDOW_DAYS : max(0, (int) $value);
    }

    /** @return list<string> */
    public function nextStatuses(string $status): array
    {
        return self::NEXT[$status] ?? [];
    }

    /**
     * Items (order_item_id => units) a customer can still ask to return.
     *
     * @param array<string, mixed> $order order with `items`
     * @return array<int, int>
     */
    public function returnable(array $order, ?\DateTimeInterface $now = null): array
    {
        $days = $this->windowDays();
        if ($days === 0 || !in_array($order['status'], ['shipped', 'completed'], true)) {
            return [];
        }
        $from = strtotime((string) ($order['shipped_at'] ?? '') ?: (string) $order['created_at']);
        if ($from === false || ($now?->getTimestamp() ?? time()) > $from + $days * 86400) {
            return [];
        }

        $requested = $this->returns->requestedQuantities((int) $order['id']);
        $left = [];
        foreach ($order['items'] as $item) {
            $remaining = (int) $item['quantity'] - ($requested[(int) $item['id']] ?? 0);
            if ($remaining > 0) {
                $left[(int) $item['id']] = $remaining;
            }
        }

        return $left;
    }

    /**
     * @param array<string, mixed> $order order with `items`
     * @param array<int|string, mixed> $quantities posted order_item_id => units
     * @throws RuntimeException with a message safe to show the customer
     */
    public function request(array $order, array $quantities, string $reason, string $details): int
    {
        $left = $this->returnable($order);
        if ($left === []) {
            throw new RuntimeException($this->translator->trans('This order can\'t be returned (anymore).'));
        }
        if (!isset(self::REASONS[$reason])) {
            throw new RuntimeException($this->translator->trans('Please choose a reason for the return.'));
        }

        $chosen = [];
        foreach ($quantities as $itemId => $qty) {
            $qty = (int) $qty;
            if ($qty < 1) {
                continue;
            }
            if (!isset($left[(int) $itemId]) || $qty > $left[(int) $itemId]) {
                throw new RuntimeException($this->translator->trans('You chose more items than can still be returned.'));
            }
            $chosen[(int) $itemId] = $qty;
        }
        if ($chosen === []) {
            throw new RuntimeException($this->translator->trans('Choose at least one item to return.'));
        }

        $id = $this->returns->create((int) $order['id'], $reason, mb_substr(trim($details), 0, 2000) ?: null, $chosen);
        $this->notifier->requested($id);

        return $id;
    }

    /** @throws RuntimeException when the move isn't allowed */
    public function setStatus(int $id, string $status, string $response): void
    {
        $request = $this->returns->find($id) ?? throw new RuntimeException($this->translator->trans('Return request not found.'));
        if (!in_array($status, self::NEXT[$request['status']] ?? [], true)) {
            throw new RuntimeException($this->translator->trans('That change isn\'t possible from the current status.'));
        }
        $response = mb_substr(trim($response), 0, 2000);
        $this->returns->setStatus($id, $status, $response !== '' ? $response : ($request['admin_response'] ?? null));
        if (in_array($status, ['approved', 'rejected', 'received'], true)) {
            $this->notifier->updated($id);
        }
    }
}
