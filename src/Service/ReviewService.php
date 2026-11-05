<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Repository\ReviewRepository;
use App\Repository\SettingRepository;
use RuntimeException;

/** Review rules: signed-in customers only, one review per product, moderation unless auto-approve is on. */
final class ReviewService
{
    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly SettingRepository $settings,
        private readonly Translator $translator,
    ) {
    }

    public function autoApprove(): bool
    {
        return $this->settings->get('reviews_auto_approve', '0') === '1';
    }

    /** "Maria Silva Santos" → "Maria S." */
    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr((string) ($parts[0] ?? ''), 0, 40);
        if (count($parts) > 1) {
            return $first . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
        }

        return $first;
    }

    /**
     * @param array<string, mixed> $customer id, name
     * @return array{id: int, status: string}
     * @throws RuntimeException with a message safe to show the customer
     */
    public function submit(array $customer, int $productId, mixed $rating, string $title, string $body): array
    {
        $rating = filter_var($rating, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        if ($rating === false) {
            throw new RuntimeException($this->translator->trans('Please choose a rating from 1 to 5 stars.'));
        }
        $title = mb_substr(trim($title), 0, 120);
        $body = mb_substr(trim($body), 0, 3000);
        if ($title === '' && $body === '') {
            throw new RuntimeException($this->translator->trans('Please write a few words about the product.'));
        }
        $customerId = (int) $customer['id'];
        if ($this->reviews->findByCustomer($productId, $customerId) !== null) {
            throw new RuntimeException($this->translator->trans('You have already reviewed this product.'));
        }

        $status = $this->autoApprove() ? 'approved' : 'pending';
        $id = $this->reviews->create(
            $productId,
            $customerId,
            self::displayName((string) $customer['name']),
            $rating,
            $title !== '' ? $title : null,
            $body !== '' ? $body : null,
            $status,
            $this->reviews->hasPurchased($customerId, $productId)
        );

        return ['id' => $id, 'status' => $status];
    }
}
