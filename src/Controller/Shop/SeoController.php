<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Http\Router;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Service\SiteUrl;
use App\Support\Slug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /robots.txt and /sitemap.xml for search engines (absolute URLs from APP_URL). */
final class SeoController
{
    /** Info pages listed in the sitemap (route names). */
    private const PAGES = ['page.about', 'page.info', 'page.support', 'page.terms'];

    public function __construct(
        private readonly Responder $responder,
        private readonly Router $router,
        private readonly SiteUrl $siteUrl,
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function robots(ServerRequestInterface $request): ResponseInterface
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /account',
            'Disallow: /api/',
            '',
            'Sitemap: ' . $this->siteUrl->absolute($this->router->url('sitemap')),
        ];

        return $this->responder->text(implode("\n", $lines) . "\n");
    }

    public function sitemap(ServerRequestInterface $request): ResponseInterface
    {
        $urls = [['loc' => $this->router->url('home'), 'lastmod' => null]];
        foreach ($this->categories->findAll() as $category) {
            $urls[] = ['loc' => $this->router->url('home', [], ['category' => (string) $category['slug']]), 'lastmod' => null];
        }
        foreach ($this->products->findVisibleForSitemap() as $product) {
            $urls[] = [
                'loc'     => $this->router->url('product.show', [
                    'id'   => (int) $product['id'],
                    'slug' => Slug::from((string) $product['name'], 'product'),
                ]),
                'lastmod' => $product['updated_at'] ? date('Y-m-d', (int) strtotime((string) $product['updated_at'])) : null,
            ];
        }
        foreach (self::PAGES as $route) {
            $urls[] = ['loc' => $this->router->url($route), 'lastmod' => null];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($this->siteUrl->absolute($url['loc']), ENT_XML1 | ENT_QUOTES) . '</loc>'
                . ($url['lastmod'] !== null ? '<lastmod>' . $url['lastmod'] . '</lastmod>' : '')
                . "</url>\n";
        }
        $xml .= "</urlset>\n";

        return $this->responder->text($xml)->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
