<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\I18n\Translator;
use App\Service\CartService;
use App\Service\StoreSettings;
use App\Support\Money;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CartService $cart,
        private readonly StoreSettings $store,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $removed = $this->cart->removeUnavailable();
        $items = $this->cart->items();

        return $this->responder->view($request, 'shop/cart.html.twig', [
            'items'        => $items,
            'total'        => $this->cart->total($items),
            'removed'      => $removed,
            'money_format' => Money::spec($this->store->currency(), $this->translator->intlLocale()),
        ]);
    }
}
