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
use App\Support\Slug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Product page at /product/{id}-{slug}; other spellings (/product/{id}, old slugs) get a 301. */
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

    public function show(ServerRequestInterface $request, int $id, int|string|null $slug = null): ResponseInterface
    {
        $product = $this->products->findVisibleById($id)
            ?? throw HttpException::notFound('Sorry, that product could not be found.');

        $canonicalSlug = Slug::from((string) $product['name'], 'product');
        if ((string) $slug !== $canonicalSlug) {
            parse_str($request->getUri()->getQuery(), $query);

            return $this->responder->redirectToRoute('product.show', ['id' => $id, 'slug' => $canonicalSlug], $query, 301);
        }

        $this->pageViews->record($this->session->id(), 'product_view', $id, $request->getUri()->getPath());

        return $this->responder->view($request, 'shop/product.html.twig', [
            'product'          => $product,
            'images'           => $this->images->gallery($product),
            'variants'         => $this->variants->findAvailableForProduct($id),
            'description_html' => $this->sanitizer->clean((string) $product['long_description']),
            'related'          => $this->images->forListing($this->products->findRelated($id, (int) $product['category_id'])),
        ]);
    }
}
