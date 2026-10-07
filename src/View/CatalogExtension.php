<?php

declare(strict_types=1);

namespace App\View;

use App\Http\Router;
use App\Service\SiteUrl;
use App\Service\StoreSettings;
use App\Service\Thumbnails;
use App\Support\Slug;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Storefront catalog helpers for application views:
 *   product_url(product)            canonical product URL (/product/12-green-tea)
 *   thumb(path, 400)                URL of a resized copy (never upscaled)
 *   srcset(path)                    "…400w, …800w, original 1200w"
 *   image_size(path)                {width, height} or null
 *   absolute_url('/path')           APP_URL + path
 *   json_ld({...})                  <script type="application/ld+json">
 *   name|slug                       "green-tea"
 * Globals: low_stock_threshold. (The <html lang> comes from html_lang(), see TranslationExtension.)
 */
final class CatalogExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly Thumbnails $thumbnails,
        private readonly SiteUrl $siteUrl,
        private readonly StoreSettings $store,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('product_url', $this->productUrl(...)),
            new TwigFunction('thumb', $this->thumbnails->url(...)),
            new TwigFunction('srcset', $this->thumbnails->srcset(...)),
            new TwigFunction('image_size', $this->thumbnails->size(...)),
            new TwigFunction('absolute_url', $this->siteUrl->absolute(...)),
            new TwigFunction('json_ld', $this->jsonLd(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('slug', static fn (?string $text): string => Slug::from((string) $text, 'product')),
        ];
    }

    public function getGlobals(): array
    {
        return [
            // "Only N left" at or below this (Admin → Settings, default 5).
            'low_stock_threshold' => $this->store->lowStockThreshold(),
        ];
    }

    /** @param array<string, mixed> $product needs id and name */
    public function productUrl(array $product): string
    {
        return $this->router->url('product.show', [
            'id'   => (int) $product['id'],
            'slug' => Slug::from((string) ($product['name'] ?? ''), 'product'),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function jsonLd(array $data): string
    {
        $json = json_encode(
            self::withoutNulls(['@context' => 'https://schema.org'] + $data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );

        return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function withoutNulls(array $data): array
    {
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::withoutNulls($value);
            }
        }

        return $isList ? array_values($data) : $data; // keep lists as JSON arrays
    }
}
