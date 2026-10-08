<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\Responder;
use App\I18n\Translator;
use App\Repository\VariantRepository;
use App\Service\CartService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JSON cart endpoint used by assets/js/app.js.
 * GET returns the cart; POST with action=add|update|remove changes it.
 */
final class CartApiController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CartService $cart,
        private readonly VariantRepository $variants,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->state());
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $variantId = (int) ($body['variant_id'] ?? 0);
        $quantity = (int) ($body['quantity'] ?? 1);

        try {
            match ((string) ($body['action'] ?? '')) {
                // What's already in the cart counts towards the stock limit.
                'add' => $this->cart->add(
                    $this->checkStock($variantId, $this->cart->quantityOf($variantId) + max(1, $quantity)),
                    max(1, $quantity)
                ),
                'update' => $this->cart->update($quantity > 0 ? $this->checkStock($variantId, $quantity) : $variantId, $quantity),
                'remove' => $this->cart->remove($variantId),
                default => throw new InvalidArgumentException($this->translator->trans('Unknown cart action.')),
            };
        } catch (InvalidArgumentException $e) {
            return $this->responder->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->responder->json($this->state());
    }

    /** Returns the variant id if it can be bought in this (total) quantity. */
    private function checkStock(int $variantId, int $quantity): int
    {
        $variant = $this->variants->findAvailable($variantId)
            ?? throw new InvalidArgumentException($this->translator->trans('That option is not available.'));
        if ((int) $variant['stock'] < $quantity) {
            throw new InvalidArgumentException($this->translator->transPlural('Not enough stock available — only {count} left.', max(0, (int) $variant['stock'])));
        }

        return $variantId;
    }

    /** @return array{success: true, items: list<array<string, mixed>>, count: int, total: float} */
    private function state(): array
    {
        $items = $this->cart->items();

        return ['success' => true, 'items' => $items, 'count' => $this->cart->count($items), 'total' => $this->cart->total($items)];
    }
}
