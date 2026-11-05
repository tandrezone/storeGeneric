<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Tests\HttpTestCase;

final class StorefrontSmokeTest extends HttpTestCase
{
    public function testPublicPagesRender(): void
    {
        $ids = $this->makeProduct($this->sku(), 12.5, 5, 'Smoke product');
        $this->pdo()->prepare("UPDATE products SET import_status = 'approved', is_active = 1 WHERE id = :id")->execute(['id' => $ids['product']]);

        foreach (['/', '/cart', '/checkout', '/about', '/info', '/support', '/terms', '/privacy', '/order/track', '/account/login', '/account/register', '/robots.txt', '/sitemap.xml'] as $path) {
            $response = $this->get_($path);
            $this->assertTrue(in_array($response->getStatusCode(), [200, 302], true), "$path returned {$response->getStatusCode()}");
        }
        // The test database fills up with products over the runs, so look for it by name.
        $home = $this->html($this->get_('/', ['q' => 'Smoke product']));
        $this->assertStringContainsString('Smoke product', $home);
        $bare = $this->get_('/product/' . $ids['product']);
        $this->assertStatus(301, $bare); // canonical URL carries the slug
        $this->assertStatus(200, $this->get_(parse_url($bare->getHeaderLine('Location'), PHP_URL_PATH)));
    }

    public function testUnknownPageIs404(): void
    {
        $this->assertStatus(404, $this->get_('/definitely-not-a-page'));
    }

    public function testAdminAreaNeedsLogin(): void
    {
        $this->assertRedirectsTo('/admin/login', $this->get_('/admin'));
        $this->assertRedirectsTo('/admin/login', $this->get_('/admin/orders'));
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->loginAdmin();
        $response = $this->request('POST', '/admin/discounts', ['action' => 'create', 'csrf_token' => 'wrong']);
        $this->assertStatus(400, $response);
    }
}
