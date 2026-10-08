<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\ProductRepository;
use App\Repository\ProductTranslationRepository;
use App\Service\ProductJsonExporter;
use App\Service\ProductJsonImporter;
use App\Service\ProductTranslations;
use App\Support\Paths;
use RuntimeException;
use Tests\IntegrationTestCase;

final class ProductJsonTest extends IntegrationTestCase
{
    /** 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> files written under public/ by a test, removed afterwards */
    private array $files = [];

    public function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    public function testExportListsEverythingAboutAProduct(): void
    {
        $sku = $this->sku('J');
        $ids = $this->makeProduct($sku, 12.5, 7, 'Jade plant');
        $this->get(ProductTranslations::class)->save($ids['product'], 'pt', ['name' => 'Planta de jade']);
        $path = $this->storeLocalImage($ids['product']);

        $product = $this->exported($ids['product'], false);

        $this->assertSame('Jade plant', $product['name']);
        $this->assertSame('approved', $product['status']);
        $this->assertSame($sku, $product['variants'][0]['sku']);
        $this->assertSame('12.50', $product['variants'][0]['price']);
        $this->assertSame(7, $product['variants'][0]['stock']);
        $this->assertSame('Planta de jade', $product['translations']['pt']['name']);
        $this->assertSame($path, $product['images'][0]['path']);
        $this->assertTrue(str_ends_with($product['images'][0]['url'], '/' . $path), 'every image has an absolute URL');
        $this->assertTrue(!isset($product['images'][0]['data']), 'bytes are only embedded on request');

        $embedded = $this->exported($ids['product'], true);
        $this->assertSame(base64_encode((string) file_get_contents($this->get(Paths::class)->public($path))), $embedded['images'][0]['data']);
    }

    public function testImportingYourOwnExportChangesNothing(): void
    {
        $ids = $this->makeProduct($this->sku('S'), 5.0, 3, 'Round trip');
        $this->storeLocalImage($ids['product']);
        $products = $this->parse([$this->exported($ids['product'], false)]);

        $report = $this->get(ProductJsonImporter::class)->run($products, ['images' => true], true);

        $this->assertSame('unchanged', $report['rows'][0]['action']);
        $this->assertSame(1, $report['counts']['images_local'], 'an image already stored here is kept, not downloaded');
        $this->assertSame(0, $report['counts']['images_download']);
    }

    public function testReimportingAnEmbeddedExportRecognisesTheStoredImage(): void
    {
        $ids = $this->makeProduct($this->sku('R'), 5.0, 3, 'Embedded round trip');
        $this->storeLocalImage($ids['product']);
        $exported = $this->exported($ids['product'], true);
        $exported['images'][0]['path'] = 'assets/images/products/99-somewhere-else.png'; // as if it came from another shop

        $report = $this->get(ProductJsonImporter::class)->run($this->parse([$exported]), ['images' => true], true);

        $this->assertSame('unchanged', $report['rows'][0]['action'], 'the same bytes are already stored for this product');
        $this->assertSame(1, $report['counts']['images_local']);
        $this->assertSame(0, $report['counts']['images_embedded']);
        $this->assertSame(1, count($this->get(ProductRepository::class)->imagePaths($ids['product'])));
    }

    public function testCreatesProductsFromAnotherShopAndStoresTheirImages(): void
    {
        $sku = $this->sku('N');
        $document = [[
            'name'              => 'Imported cactus',
            'short_description' => 'Spiky',
            'long_description'  => '<p>Prickly.</p><script>x()</script>',
            'status'            => 'approved',
            'is_active'         => true,
            'category'          => ['name' => 'Imported cacti ' . bin2hex(random_bytes(3)), 'description' => 'From elsewhere'],
            'images'            => [
                ['path' => 'assets/images/products/9-elsewhere.png', 'url' => 'https://example.invalid/nope.png', 'data' => self::PNG],
                ['path' => 'assets/images/products/9-other.png', 'url' => 'http://127.0.0.1/private.png'],
                ['path' => 'assets/images/products/9-third.png'],
            ],
            'variants'          => [['sku' => $sku, 'label' => '1', 'unit' => 'pc', 'price' => '4.50', 'stock' => 12, 'is_active' => true]],
            'translations'      => ['pt' => ['name' => 'Cato importado'], 'xx' => ['name' => 'nope']],
        ]];
        $importer = $this->get(ProductJsonImporter::class);

        // No category yet: an error unless categories may be created.
        $strict = $importer->run($this->parse($document), ['create_categories' => false], false);
        $this->assertSame('error', $strict['rows'][0]['action']);

        $preview = $importer->run($this->parse($document), ['create_categories' => true], false);
        $this->assertSame('create', $preview['rows'][0]['action']);
        $this->assertSame(1, $preview['counts']['images_embedded']);
        $this->assertSame(1, $preview['counts']['images_download']);
        $this->assertSame(1, $preview['rows'][0]['images']['missing']);
        $this->assertSame([], $this->variantsOf($sku), 'a preview writes nothing');

        $report = $importer->run($this->parse($document), ['create_categories' => true], true);
        $row = $report['rows'][0];
        $this->assertSame('create', $row['action'], implode('; ', $row['errors']));
        $this->assertSame(1, $report['counts']['images_failed'], 'the private address is refused, and that does not fail the product');
        $this->assertSame(1, $report['counts']['new_categories']);

        $variant = $this->variantsOf($sku)[0];
        $product = $this->get(ProductRepository::class)->findForAdmin((int) $variant['product_id']);
        $this->assertSame('Imported cactus', $product['name']);
        $this->assertSame('approved', $product['import_status']);
        $this->assertTrue(!str_contains((string) $product['long_description'], 'script'), 'the description is sanitized');
        $this->assertSame('4.50', $variant['price']);
        $this->assertSame('Cato importado', $this->get(ProductTranslationRepository::class)->forProduct((int) $product['id'])['pt']['name']);

        $images = $this->get(ProductRepository::class)->imagePaths((int) $product['id']);
        $this->assertSame(1, count($images), 'only the embedded image could be stored');
        $this->assertTrue(str_starts_with($images[0], 'assets/images/products/' . $product['id'] . '-'));
        $this->assertTrue(is_file($this->get(Paths::class)->public($images[0])), 'the image is stored locally');
        $this->files[] = $this->get(Paths::class)->public($images[0]);
        $this->assertSame($images[0], $product['image_path'], 'the first image becomes the main one');
    }

    public function testUpdatesMatchedProductsBySku(): void
    {
        $sku = $this->sku('U');
        $ids = $this->makeProduct($sku, 10.0, 5, 'Old name');
        $importer = $this->get(ProductJsonImporter::class);
        $document = [[
            'name'     => 'New name',
            'variants' => [
                ['sku' => $sku, 'price' => 11, 'stock' => 8, 'is_active' => true],
                ['sku' => $sku . '-X', 'price' => 3, 'stock' => 1],
            ],
        ]];

        $skipped = $importer->run($this->parse($document), ['update_existing' => false], true);
        $this->assertSame('skip', $skipped['rows'][0]['action']);
        $this->assertSame('Old name', $this->get(ProductRepository::class)->findForAdmin($ids['product'])['name']);

        $report = $importer->run($this->parse($document), ['update_existing' => true], true);
        $this->assertSame('update', $report['rows'][0]['action']);
        $this->assertSame(1, $report['counts']['variants_added']);
        $this->assertSame(1, $report['counts']['variants_updated']);
        $this->assertSame('New name', $this->get(ProductRepository::class)->findForAdmin($ids['product'])['name']);
        $this->assertSame('11.00', $this->variantsOf($sku)[0]['price']);
        $this->assertSame(2, count($this->get(\App\Repository\VariantRepository::class)->findForProducts([$ids['product']])[$ids['product']]));
        $this->assertSame('Short', $this->get(ProductRepository::class)->findForAdmin($ids['product'])['short_description'], 'fields missing from the file are left alone');
    }

    public function testBadDataIsReportedPerProduct(): void
    {
        $other = $this->makeProduct($this->sku('E'), 1.0, 1, 'Other');
        $existing = $this->variantsOfProduct($other['product'])[0]['sku'];
        $report = $this->get(ProductJsonImporter::class)->run($this->parse([
            ['name' => 'Bad price', 'short_description' => 's', 'category' => 'Whatever', 'variants' => [['sku' => $this->sku('B'), 'price' => -1]]],
            ['name' => 'Twice', 'short_description' => 's', 'category' => 'Whatever', 'variants' => [['sku' => 'dup-1', 'price' => 1], ['sku' => 'DUP-1', 'price' => 1]]],
            ['short_description' => 's', 'category' => 'Whatever', 'variants' => [['sku' => $this->sku('M'), 'price' => 1]]],
            'not a product',
        ]), ['create_categories' => true], false);

        foreach ($report['rows'] as $row) {
            $this->assertSame('error', $row['action'], $row['name']);
            $this->assertTrue($row['errors'] !== []);
        }
        $this->assertSame(4, $report['counts']['error']);
        $this->assertTrue($existing !== '');
    }

    public function testRefusesFilesThatAreNotExports(): void
    {
        $importer = $this->get(ProductJsonImporter::class);
        foreach (['not json', '{"format":"something-else","products":[]}', '{"format":"storegeneric-products","version":99,"products":[{}]}', '{"format":"storegeneric-products","version":1}'] as $text) {
            $threw = false;
            try {
                $importer->parse($text);
            } catch (RuntimeException) {
                $threw = true;
            }
            $this->assertTrue($threw, $text);
        }
        $this->assertSame(1, count($importer->parse('[{"name":"A bare list is fine"}]')));
    }

    /** @return array<string, mixed> one product's entry of the export */
    private function exported(int $productId, bool $embed): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->get(ProductJsonExporter::class)->write($handle, null, null, $embed);
        rewind($handle);
        $document = json_decode((string) stream_get_contents($handle), true, 64, JSON_THROW_ON_ERROR);
        fclose($handle);

        $this->assertSame('storegeneric-products', $document['format']);
        foreach ($document['products'] as $product) {
            if ($product['id'] === $productId) {
                return $product;
            }
        }
        $this->fail('the product is not in the export');
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<mixed>
     */
    private function parse(array $products): array
    {
        return $this->get(ProductJsonImporter::class)->parse((string) json_encode(['format' => 'storegeneric-products', 'version' => 1, 'products' => $products]));
    }

    private function storeLocalImage(int $productId): string
    {
        $paths = $this->get(Paths::class);
        $relative = 'assets/images/products/' . $productId . '-test' . bin2hex(random_bytes(3)) . '.png';
        $dir = dirname($paths->public($relative));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($paths->public($relative), base64_decode(self::PNG));
        $this->files[] = $paths->public($relative);
        $this->get(ProductRepository::class)->saveImagePaths($productId, [$relative]);

        return $relative;
    }

    /** @return list<array<string, mixed>> */
    private function variantsOf(string $sku): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM product_variants WHERE sku = ?');
        $stmt->execute([$sku]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function variantsOfProduct(int $productId): array
    {
        return $this->get(\App\Repository\VariantRepository::class)->findForProducts([$productId])[$productId] ?? [];
    }
}
