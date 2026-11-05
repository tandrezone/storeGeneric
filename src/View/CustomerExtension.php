<?php

declare(strict_types=1);

namespace App\View;

use App\Http\Router;
use App\Security\CustomerAuthenticator;
use App\Repository\ReturnRepository;
use App\Service\OrderLinks;
use App\Service\ReturnService;
use PDOException;
use Psr\Container\ContainerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Storefront account helper for application views:
 *   customer()   the signed-in customer (id, email, name, phone, email_verified_at, …) or null
 *
 * Used by the shop layout on every page, error pages included, so a
 * database failure here means "not signed in" rather than a second error.
 */
final class CustomerExtension extends AbstractExtension
{
    public function __construct(
        private readonly CustomerAuthenticator $auth,
        private readonly OrderLinks $links,
        private readonly Router $router,
        // Resolved lazily: these services send email through View, which owns this extension.
        private readonly ContainerInterface $container,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('customer', $this->customer(...)),
            new TwigFunction('document_url', $this->documentUrl(...)),
            new TwigFunction('return_state', $this->returnState(...)),
        ];
    }

    /**
     * Link to the invoice (or credit note) PDF of an order, or '' when there isn't one yet
     * (unpaid orders have no invoice; a credit note needs a refund).
     *
     * @param array<string, mixed> $order
     */
    private function documentUrl(array $order, string $kind = 'invoice'): string
    {
        $paid = in_array($order['payment_status'] ?? '', ['paid', 'refunded'], true);
        $ok = $kind === 'credit_note' ? ($order['status'] ?? '') === 'refunded' || ($order['payment_status'] ?? '') === 'refunded' : $paid;

        return $ok ? $this->router->url('order.invoice', [], $this->links->query((string) $order['order_number']) + ['type' => $kind]) : '';
    }

    /**
     * Return requests of an order and whether more items can still be returned.
     *
     * @param array<string, mixed> $order order with `items`
     * @return array{url: string, can_request: bool, requests: list<array<string, mixed>>}
     */
    private function returnState(array $order): array
    {
        try {
            return [
                'url'         => $this->router->url('order.return', [], $this->links->query((string) $order['order_number'])),
                'can_request' => $this->container->get(ReturnService::class)->returnable($order) !== [],
                'requests'    => $this->container->get(ReturnRepository::class)->forOrder((int) $order['id']),
            ];
        } catch (PDOException) {
            return ['url' => '', 'can_request' => false, 'requests' => []]; // store not migrated yet
        }
    }

    /** @return array<string, mixed>|null */
    private function customer(): ?array
    {
        try {
            return $this->auth->customer();
        } catch (PDOException) {
            return null;
        }
    }
}
