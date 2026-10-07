<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\Repository\PageViewRepository;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;
use App\Security\HtmlSanitizer;
use App\Service\ProductImages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProductController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly ProductImages $images,
        private readonly HtmlSanitizer $sanitizer,
        private readonly PageViewRepository $pageViews,
        private readonly Session $session,
    ) {
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $product = $this->products->findVisibleById($id)
            ?? throw HttpException::notFound('Sorry, that product could not be found.');

        $this->pageViews->record($this->session->id(), 'product_view', $id, $request->getUri()->getPath());

        return $this->responder->view($request, 'shop/product.html.twig', [
            'product'          => $product,
            'images'           => $this->images->gallery($product),
            'variants'         => $this->variants->findAvailableForProduct($id),
            'description_html' => $this->sanitizer->clean((string) $product['long_description']),
        ]);
    }
}
