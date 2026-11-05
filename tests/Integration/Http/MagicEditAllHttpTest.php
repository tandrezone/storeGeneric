<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Tests\HttpTestCase;

/** "Magic edit all products": listing every product id and saving reviewed suggestions. */
final class MagicEditAllHttpTest extends HttpTestCase
{
    /** @return array<string, mixed> */
    private function json(string $method, string $path, array $body = []): array
    {
        return (array) json_decode($this->html($this->request($method, $path, $body)), true);
    }

    /** @return array<string, string> */
    private function product(int $id): array
    {
        return $this->pdo()->query("SELECT name, short_description, long_description FROM products WHERE id = $id")->fetch();
    }

    public function testListsEveryProductIdRegardlessOfStatus(): void
    {
        $this->loginAdmin('staff');
        $a = $this->makeProduct($this->sku('M'))['product'];
        $b = $this->makeProduct($this->sku('M'))['product'];
        $this->pdo()->exec("UPDATE products SET import_status = 'created', is_active = 0 WHERE id = $b");

        $data = $this->json('GET', '/admin/api/magic-edit/products');
        $this->assertTrue($data['success']);
        $this->assertContains($a, $data['ids']);
        $this->assertContains($b, $data['ids']);
    }

    public function testApplySavesOnlyTheGivenFieldsAndSanitizesHtml(): void
    {
        $this->loginAdmin('staff');
        $id = $this->makeProduct($this->sku('M'), 10.0, 5, 'Old name')['product'];

        $data = $this->json('POST', '/admin/api/magic-edit/apply', [
            'product_id'       => $id,
            'long_description' => '<p>New <strong>text</strong></p><script>alert(1)</script>',
        ]);
        $this->assertTrue($data['success']);

        $row = $this->product($id);
        $this->assertSame('Old name', $row['name'], 'fields that were not sent stay as they are');
        $this->assertSame('Short', $row['short_description']);
        $this->assertStringContainsString('<strong>text</strong>', $row['long_description']);
        $this->assertStringNotContainsString('<script', $row['long_description']);
    }

    public function testApplyRejectsInvalidValuesAndUnknownProducts(): void
    {
        $this->loginAdmin('staff');
        $id = $this->makeProduct($this->sku('M'), 10.0, 5, 'Keep me')['product'];

        $this->assertStatus(422, $this->post('/admin/api/magic-edit/apply', ['product_id' => $id, 'name' => '   ']));
        $this->assertStatus(422, $this->post('/admin/api/magic-edit/apply', ['product_id' => $id, 'name' => str_repeat('x', 181)]));
        $this->assertStatus(422, $this->post('/admin/api/magic-edit/apply', ['product_id' => $id]));
        $this->assertStatus(404, $this->post('/admin/api/magic-edit/apply', ['product_id' => 99999999, 'name' => 'X']));
        $this->assertSame('Keep me', $this->product($id)['name']);
    }

    public function testNeedsAnAdminLogin(): void
    {
        $id = $this->makeProduct($this->sku('M'))['product'];
        $this->assertStatus(401, $this->post('/admin/api/magic-edit/apply', ['product_id' => $id, 'name' => 'Hacked']));
        $this->assertStatus(401, $this->get_('/admin/api/magic-edit/products'));
        $this->assertNotSame('Hacked', $this->product($id)['name']);
    }

    public function testProductsPageShowsTheMagicEditAllPanel(): void
    {
        $this->loginAdmin('staff');
        $page = $this->html($this->get_('/admin/products'));
        $this->assertStringContainsString('Magic edit all products', $page);
        $this->assertStringContainsString('/admin/api/magic-edit/apply', $page);
        $this->assertTrue((bool) preg_match('#/assets/js/admin\.js\?v=\d+#', $page), 'versioned, so browsers never run a stale copy');
    }
}
