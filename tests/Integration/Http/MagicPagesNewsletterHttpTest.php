<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Service\AiStoreContext;
use Tests\HttpTestCase;

/**
 * Magic edit for Admin → Pages and Admin → Newsletter. Gemini is switched
 * off (no API key), so these cover access, validation and the store context
 * the prompt is built from — everything up to the AI call.
 */
final class MagicPagesNewsletterHttpTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        putenv('GEMINI_API_KEY='); // never call the real API from tests
    }

    public function tearDown(): void
    {
        putenv('GEMINI_API_KEY');
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function json(string $path, array $body): array
    {
        return (array) json_decode($this->html($this->post($path, $body)), true);
    }

    public function testPageAndNewsletterFormsShowTheMagicEdit(): void
    {
        $this->loginAdmin('owner');
        $pages = $this->html($this->get_('/admin/pages', ['slug' => 'about']));
        $this->assertStringContainsString('page-magic', $pages);
        $this->assertStringContainsString('/admin/api/magic-page', $pages);
        $this->assertTrue((bool) preg_match('#/assets/js/admin\.js\?v=\d+#', $pages), 'admin.js runs the editor and the magic edit');

        $newsletter = $this->html($this->get_('/admin/newsletter'));
        $this->assertStringContainsString('newsletter-magic', $newsletter);
        $this->assertStringContainsString('/admin/api/magic-newsletter', $newsletter);
    }

    public function testRequestsReachTheAiOnlyWithEnoughToGoOn(): void
    {
        $this->loginAdmin('owner');
        $this->makeProduct($this->sku('P'), 12.5, 5, 'Magic mug');

        $this->assertStatus(400, $this->post('/admin/api/magic-page', ['slug' => '', 'title' => '']));

        $page = $this->json('/admin/api/magic-page', ['slug' => 'about', 'instruction' => 'friendly']);
        $this->assertFalse($page['success']);
        $this->assertSame('GEMINI_API_KEY is not set.', $page['error'], 'the prompt was built and only the AI call failed');

        $news = $this->json('/admin/api/magic-newsletter', ['instruction' => 'spring sale']);
        $this->assertFalse($news['success']);
        $this->assertSame('GEMINI_API_KEY is not set.', $news['error']);
    }

    public function testNeedsAManagerLikeThePagesThemselves(): void
    {
        $this->assertStatus(401, $this->post('/admin/api/magic-page', ['slug' => 'about']));
        $this->assertStatus(401, $this->post('/admin/api/magic-newsletter', []));

        $this->loginAdmin('staff');
        $this->assertStatus(403, $this->post('/admin/api/magic-page', ['slug' => 'about']));
        $this->assertStatus(403, $this->post('/admin/api/magic-newsletter', []));
    }

    public function testStoreContextListsVisibleProductsWithSalesAndLinks(): void
    {
        $sale = $this->makeProduct($this->sku('S'), 20.0, 5, 'Discounted teapot');
        $this->pdo()->exec("UPDATE product_variants SET sale_price = 15.00 WHERE id = {$sale['variant']}");
        $hidden = $this->makeProduct($this->sku('H'), 9.0, 5, 'Secret draft product');
        $this->pdo()->exec("UPDATE products SET import_status = 'created' WHERE id = {$hidden['product']}");

        $context = $this->get(AiStoreContext::class);
        $catalog = $context->catalog(500, true);
        $this->assertStringContainsString('Discounted teapot — from 15.00 EUR (ON SALE, was 20.00 EUR)', $catalog);
        $this->assertStringContainsString('http://localhost/product/' . $sale['product'], $catalog);
        $this->assertStringNotContainsString('Secret draft product', $catalog);

        $this->assertStringContainsString('store@example.com', $context->facts());
        $this->assertStringContainsString('- Shipping: ', $context->facts());
    }
}
