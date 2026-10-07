<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Service\ProductImages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Shop front page: product grid, optionally filtered by category (?category=slug). */
final class HomeController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly ProductImages $images,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $slug = trim((string) ($request->getQueryParams()['category'] ?? '')) ?: null;
        if ($slug !== null && $this->categories->findBySlug($slug) === null) {
            $slug = null;
        }

        return $this->responder->view($request, 'shop/home.html.twig', [
            'categories'      => $this->categories->findAll(),
            'products'        => $this->images->forListing($this->products->findVisible($slug)),
            'active_category' => $slug,
        ]);
    }
}
