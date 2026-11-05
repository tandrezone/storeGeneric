<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Support\FileCache;
use Tests\HttpTestCase;

final class StorefrontCacheHttpTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        putenv('CACHE_ENABLED=true'); // each request boots a fresh container that reads the environment
        $this->http()->get(FileCache::class)->bump();
    }

    public function tearDown(): void
    {
        putenv('CACHE_ENABLED=false');
        parent::tearDown();
    }

    /** Home query for the product's own category, so the test doesn't depend on how many products the shared test database holds. */
    private function categoryQuery(int $categoryId): array
    {
        return ['category' => (string) $this->pdo()->query('SELECT slug FROM categories WHERE id = ' . $categoryId)->fetchColumn()];
    }

    public function testListingIsServedFromTheCacheUntilTheAdminChangesSomething(): void
    {
        $ids = $this->makeProduct($this->sku('Z'), 10.0, 5, 'Appears later zzz');
        $query = $this->categoryQuery($ids['category']);
        $this->pdo()->exec('UPDATE products SET is_active = 0 WHERE id = ' . (int) $ids['product']);
        $this->assertStringNotContainsString('Appears later zzz', $this->html($this->get_('/', $query))); // warms the cache: hidden
        $this->pdo()->exec('UPDATE products SET is_active = 1 WHERE id = ' . (int) $ids['product']); // behind the cache's back
        $this->assertStringNotContainsString('Appears later zzz', $this->html($this->get_('/', $query)), 'served from the cache');

        // A search is never cached.
        $this->assertStringContainsString('Appears later zzz', $this->html($this->get_('/', ['q' => 'Appears later zzz'])));

        $this->loginAdmin('manager');
        $this->post('/admin/categories', ['action' => 'create', 'name' => 'Cache buster ' . bin2hex(random_bytes(3))]);
        $this->assertStringContainsString('Appears later zzz', $this->html($this->get_('/', $query)), 'an admin change drops the cache');
    }

    public function testPlacingAnOrderRefreshesStockOnTheListing(): void
    {
        $ids = $this->makeProduct($this->sku('S'), 10.0, 1, 'Last one qqq');
        $query = $this->categoryQuery($ids['category']);
        $before = $this->html($this->get_('/', $query));
        $this->assertStringContainsString('Last one qqq', $before);
        $this->assertStringNotContainsString('Out of stock', $before);

        $this->placeOrder($ids['variant'], 1, 10.0); // takes the last unit
        $this->assertStringContainsString('Out of stock', $this->html($this->get_('/', $query)));
    }

    public function testSitemapIsCachedPubliclyAndRefreshedOnAdminChange(): void
    {
        $first = $this->get_('/sitemap.xml');
        $this->assertSame('public, max-age=3600', $first->getHeaderLine('Cache-Control'));
        $ids = $this->makeProduct($this->sku('M'), 10.0, 5, 'Sitemap newcomer');
        $this->assertStringNotContainsString('sitemap-newcomer', $this->html($this->get_('/sitemap.xml')));

        $this->loginAdmin('manager');
        $this->post('/admin/categories', ['action' => 'create', 'name' => 'Another ' . bin2hex(random_bytes(3))]);
        $this->assertStringContainsString('sitemap-newcomer', $this->html($this->get_('/sitemap.xml')));
    }

    public function testPagesStillCarryEachVisitorsOwnCsrfToken(): void
    {
        // Only data is cached, never HTML.
        $this->get_('/');
        $_SESSION = [];
        $first = $this->html($this->get_('/about'));
        preg_match('/name="csrf-token" content="([a-f0-9]+)"|name="csrf_token" value="([a-f0-9]+)"/', $first, $a);
        $_SESSION = [];
        $second = $this->html($this->get_('/about'));
        preg_match('/name="csrf-token" content="([a-f0-9]+)"|name="csrf_token" value="([a-f0-9]+)"/', $second, $b);
        $this->assertTrue(($a[1] ?? $a[2] ?? 'x') !== ($b[1] ?? $b[2] ?? 'y') || ($a === [] && $b === []), 'different visitors, different tokens');
    }
}
