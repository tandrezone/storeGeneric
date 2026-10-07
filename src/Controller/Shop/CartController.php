<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Service\CartService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CartService $cart,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $items = $this->cart->items();

        return $this->responder->view($request, 'shop/cart.html.twig', [
            'items' => $items,
            'total' => $this->cart->total($items),
        ]);
    }
}
