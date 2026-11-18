<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\CategoryRepository;
use App\Service\MagicProductCreator;
use ReflectionMethod;
use RuntimeException;
use Tests\IntegrationTestCase;

final class MagicProductCreatorTest extends IntegrationTestCase
{
    /** @return array<string, mixed>|null */
    private function parse(string $reply): ?array
    {
        return (new ReflectionMethod(MagicProductCreator::class, 'parse'))->invoke($this->get(MagicProductCreator::class), $reply);
    }

    public function testEveryPriceBecomesAVariantAndTheProductIsCreatedForReview(): void
    {
        $categories = $this->get(CategoryRepository::class);
        $category = ['id' => $categories->create('Cacti'), 'name' => 'Cacti'];
        $reply = '```json' . json_encode([
            'name' => 'Golden Barrel Cactus (Echinocactus grusonii)',
            'category' => strtoupper((string) $category['name']),
            'short_description' => 'A rooted golden barrel cactus in a 12 cm pot.',
            'long_description' => '<p>Rooted.</p><script>alert(1)</script><ul><li>Full sun</li></ul>',
            'variants' => [
                ['label' => 'White', 'unit' => null, 'price' => '12.50eur', 'stock' => null],
                ['label' => 'Black', 'unit' => 'pot', 'price' => 13.5, 'stock' => 4],
            ],
            'weight_grams' => 900,
        ]) . '```';

        $data = $this->parse($reply);
        $this->assertNotNull($data);
        $this->assertStringNotContainsString('<script', $data['long_description']);
        $this->assertCount(2, $data['variants']);
        $this->assertSame(12.5, $data['variants'][0]['price']);

        $creator = $this->get(MagicProductCreator::class);
        $first = $creator->save($data, []);
        $second = $creator->save($data, []); // same name again: SKUs must not clash
        $this->assertSame([], $first['warnings']);

        $product = $this->pdo()->query('SELECT category_id, CAST(import_status AS CHAR) AS status FROM products WHERE id = ' . $first['id'])->fetch();
        $this->assertSame('created', $product['status']);
        $this->assertSame((int) $category['id'], (int) $product['category_id']);

        $skus = $this->pdo()->query('SELECT sku, label, price, stock, weight_grams FROM product_variants WHERE product_id IN (' . $first['id'] . ',' . $second['id'] . ') ORDER BY id')->fetchAll();
        $this->assertCount(4, $skus);
        $this->assertSame('GOLDEN-BARREL-CACTUS-ECHINOCACTUS-GRUSON-WHITE', $skus[0]['sku']);
        $this->assertSame('GOLDEN-BARREL-CACTUS-ECHINOCACTUS-GRUSON-WHITE-2', $skus[2]['sku']);
        $this->assertSame('13.50', (string) $skus[1]['price']);
        $this->assertSame(4, (int) $skus[1]['stock']);
        $this->assertSame(900, (int) $skus[0]['weight_grams']);
    }

    public function testMissingPriceGivesAZeroPriceVariantAndAWarning(): void
    {
        $data = $this->parse('{"name":"Mystery item","category":"Brand new category","short_description":"Something.","long_description":"<p>Text</p>","variants":[],"weight_grams":null}');
        $result = $this->get(MagicProductCreator::class)->save($data, []);

        $this->assertCount(1, $result['warnings']);
        $this->assertSame(1, $result['variants']);
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM categories WHERE name = 'Brand new category'")->fetchColumn());
    }

    public function testUnreadableRepliesAndBadInputAreRejected(): void
    {
        $this->assertNull($this->parse('Sorry, I cannot help with that.'));
        $this->assertNull($this->parse('{"name":"x","short_description":"","long_description":""}'));

        $creator = $this->get(MagicProductCreator::class);
        $this->assertThrows(RuntimeException::class, fn () => $creator->prepare('   ', 'notes', []));
        $this->assertThrows(RuntimeException::class, fn () => $creator->prepare('Thing', str_repeat('x', MagicProductCreator::MAX_NOTES + 1), []));
    }
}
