<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\CartRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductTranslationRepository;
use App\Service\ProductTranslations;
use Tests\IntegrationTestCase;

final class ProductTranslationsTest extends IntegrationTestCase
{
    public function testStorefrontShowsTheTranslationAndFallsBackPerField(): void
    {
        $ids = $this->makeProduct($this->sku('T'), 10.0, 5, 'Green tea');
        $service = $this->get(ProductTranslations::class);
        $products = $this->get(ProductRepository::class);

        $errors = $service->save($ids['product'], 'pt', ['name' => 'Chá verde', 'short_description' => '', 'long_description' => '<p>Ótimo chá.</p>']);
        $this->assertSame([], $errors);

        $pt = $products->findVisibleById($ids['product'], 'pt');
        $this->assertSame('Chá verde', $pt['name']);
        $this->assertSame('Green tea', $pt['base_name'], 'the original name stays available for URLs');
        $this->assertSame('Short', $pt['short_description'], 'an empty field falls back to the original');
        $this->assertSame('<p>Ótimo chá.</p>', $pt['long_description']);

        $base = $products->findVisibleById($ids['product'], '');
        $this->assertSame('Green tea', $base['name']);
        $this->assertSame('<p>Long</p>', $base['long_description']);

        $this->assertSame('Green tea', $products->findVisibleById($ids['product'], 'fr')['name'], 'a language without a translation shows the original');
    }

    public function testListingAndSearchUseTheVisitorsLanguage(): void
    {
        $ids = $this->makeProduct($this->sku('L'), 10.0, 5, 'Zebra mug');
        $this->get(ProductTranslations::class)->save($ids['product'], 'pt', ['name' => 'Caneca zebra', 'short_description' => 'Curta']);
        $products = $this->get(ProductRepository::class);

        $names = static fn (array $rows): array => array_column($rows, 'name', 'id');

        $inPt = $names($products->findVisible(null, 'caneca', 'name', 50, 0, 'pt'));
        $this->assertSame('Caneca zebra', $inPt[$ids['product']] ?? null);
        $this->assertSame(1, $products->countVisible(null, 'caneca', 'pt'));

        $this->assertSame([], $products->findVisible(null, 'caneca', 'name', 50, 0, ''), 'the translated name is not searched in the store language');
        $this->assertSame('Zebra mug', $names($products->findVisible(null, 'zebra', 'name', 50, 0, ''))[$ids['product']] ?? null);

        $row = array_values(array_filter($products->findVisible(null, 'zebra', 'name', 50, 0, 'pt'), static fn (array $r): bool => (int) $r['id'] === $ids['product']))[0];
        $this->assertSame('Curta', $row['short_description']);
        $this->assertSame('Zebra mug', $row['base_name']);
    }

    public function testCartLinesAreTranslatedButKeepTheOriginalName(): void
    {
        $ids = $this->makeProduct($this->sku('C'), 10.0, 5, 'Blue vase');
        $this->get(ProductTranslations::class)->save($ids['product'], 'pt', ['name' => 'Vaso azul']);
        $carts = $this->get(CartRepository::class);
        $cartId = $carts->idForSession('test-' . bin2hex(random_bytes(6)));
        $carts->addItem($cartId, $ids['variant'], 1);

        $pt = $carts->items($cartId, 'pt')[0];
        $this->assertSame('Vaso azul', $pt['product_name']);
        $this->assertSame('Blue vase', $pt['base_name'], 'cart links are built from the original name');
        $this->assertSame('Blue vase', $carts->items($cartId)[0]['product_name']);
    }

    public function testSavingRulesAndCleanup(): void
    {
        $ids = $this->makeProduct($this->sku('R'));
        $service = $this->get(ProductTranslations::class);
        $rows = $this->get(ProductTranslationRepository::class);

        $this->assertTrue($service->save($ids['product'], 'en', ['name' => 'x']) !== [], 'the store language is the original, not a translation');
        $this->assertTrue($service->save($ids['product'], 'xx', ['name' => 'x']) !== [], 'unknown languages are refused');
        $this->assertTrue($service->save($ids['product'], 'pt', ['name' => str_repeat('a', 181)]) !== [], 'too-long names are refused');

        $service->save($ids['product'], 'pt', ['name' => 'Nome', 'long_description' => '<p>Olá</p><script>alert(1)</script>']);
        $saved = $rows->forProduct($ids['product'])['pt'];
        $this->assertSame('Nome', $saved['name']);
        $this->assertTrue(!str_contains((string) $saved['long_description'], 'script'), 'descriptions are sanitized');
        $this->assertSame(null, $saved['short_description']);

        $service->save($ids['product'], 'pt', ['name' => '', 'short_description' => '', 'long_description' => '<p> </p>']);
        $this->assertSame([], $rows->forProduct($ids['product']), 'saving everything empty removes the translation');

        $service->save($ids['product'], 'pt', ['name' => 'Outra']);
        $this->pdo()->exec('DELETE FROM products WHERE id = ' . $ids['product']);
        $this->assertSame([], $rows->forProduct($ids['product']), 'translations go with the product');
    }
}
