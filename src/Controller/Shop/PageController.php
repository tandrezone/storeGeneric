<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Content pages: About, Info, Support, Terms (templates/shop/pages). */
final class PageController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly PaymentRegistry $payments,
    ) {
    }

    public function about(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->view($request, 'shop/pages/about.html.twig');
    }

    public function info(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->view($request, 'shop/pages/info.html.twig', [
            'payment_methods' => array_map(
                static fn (PaymentMethod $m) => ['label' => $m->label(), 'description' => $m->description()],
                array_values($this->payments->enabled())
            ),
        ]);
    }

    public function support(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->view($request, 'shop/pages/support.html.twig');
    }

    public function terms(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->view($request, 'shop/pages/terms.html.twig');
    }
}
