<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Service\ProductImages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shop front page: product grid with optional category filter (?category=slug),
 * search (?q=), sorting (?sort=name|price_asc|price_desc|newest) and pages (?page=).
 */
final class HomeController
{
    public const PER_PAGE = 24;
    public const SORT_LABELS = [
        'name'       => 'Name',
        'price_asc'  => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'newest'     => 'Newest',
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly ProductImages $images,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $param = static fn (string $name): string => is_string($query[$name] ?? null) ? trim($query[$name]) : '';

        $slug = $param('category') ?: null;
        $category = $slug !== null ? $this->categories->findForStorefront($slug) : null;
        if ($category === null) {
            $slug = null;
        }
        $search = mb_substr($param('q'), 0, 100);
        $sort = isset(self::SORT_LABELS[$param('sort')]) ? $param('sort') : 'name';

        $total = $this->products->countVisible($slug, $search);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $param('page')));

        return $this->responder->view($request, 'shop/home.html.twig', [
            'categories'      => $this->categories->findAll(),
            'category'        => $category,
            'products'        => $this->images->forListing(
                $this->products->findVisible($slug, $search, $sort, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ),
            'active_category' => $slug,
            'search'          => $search,
            'sort'            => $sort,
            'sort_labels'     => self::SORT_LABELS,
            'total'           => $total,
            'page'            => $page,
            'pages'           => $pages,
            // Query string shared by pagination links (null values are dropped by path()).
            'filters'         => [
                'category' => $slug,
                'q'        => $search !== '' ? $search : null,
                'sort'     => $sort !== 'name' ? $sort : null,
            ],
        ]);
    }
}
