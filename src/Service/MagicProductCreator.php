<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\Admin\ProductController;
use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Infrastructure\GeminiClient;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;
use App\Security\HtmlSanitizer;
use App\Support\Slug;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * "Magic add product": the admin gives only a name / core identifier, up to
 * three photos and a dump of raw notes (specs, prices). Gemini writes the rest
 * — category, short and long description — and turns every price in the notes
 * into a variant ("white-12.50eur, black-13.50" → two variants). The product is
 * saved with status "created", so it stays off the storefront until reviewed
 * and approved.
 *
 * Split in two so the slow AI call can run without the session lock:
 * prepare() validates the input and reads the photos, generate() calls the
 * AI, save() writes the product, its variants and photos.
 */
final class MagicProductCreator
{
    public const MAX_IMAGES = 3;
    public const MAX_NOTES = 4000;
    private const MAX_VARIANTS = 20;
    private const AI_IMAGE_SIDE = 1024;

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly CategoryRepository $categories,
        private readonly ProductImageManager $images,
        private readonly HtmlSanitizer $sanitizer,
        private readonly StoreSettings $store,
        private readonly AiStoreContext $context,
        private readonly PromptLibrary $prompts,
        private readonly Translator $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Checks the form and reads the photos (downscaled copies for the AI).
     *
     * @param list<UploadedFileInterface> $uploads
     * @return array{identifier: string, notes: string, uploads: list<UploadedFileInterface>, ai_images: list<array{mime: string, data: string}>}
     */
    public function prepare(string $identifier, string $notes, array $uploads): array
    {
        $identifier = trim(preg_replace('/\s+/', ' ', $identifier) ?? '');
        $notes = trim(str_replace("\r\n", "\n", $notes));
        if ($identifier === '') {
            throw new RuntimeException($this->t('Enter the product name or core identifier.'));
        }
        if (mb_strlen($identifier) > ProductController::MAX_NAME) {
            throw new RuntimeException($this->t('Name can be at most {max} characters.', ['max' => ProductController::MAX_NAME]));
        }
        if (mb_strlen($notes) > self::MAX_NOTES) {
            throw new RuntimeException($this->t('The notes can be at most {max} characters.', ['max' => self::MAX_NOTES]));
        }

        $uploads = array_values(array_filter($uploads, static fn ($f) => $f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE));
        if (count($uploads) > self::MAX_IMAGES) {
            throw new RuntimeException($this->t('Add at most {max} photos.', ['max' => self::MAX_IMAGES]));
        }

        $aiImages = [];
        foreach ($uploads as $upload) {
            if ($upload->getError() !== UPLOAD_ERR_OK || (int) $upload->getSize() > ImageUploader::MAX_BYTES) {
                throw new RuntimeException($this->t('Photo upload failed — use JPG, PNG or WebP images up to {mb} MB.', ['mb' => ImageUploader::MAX_BYTES >> 20]));
            }
            $stream = $upload->getStream();
            $stream->rewind();
            $bytes = $stream->getContents();
            $stream->rewind();
            $image = $this->forAi($bytes);
            if ($image === null) {
                throw new RuntimeException($this->t('Photo upload failed — use JPG, PNG or WebP images up to {mb} MB.', ['mb' => ImageUploader::MAX_BYTES >> 20]));
            }
            $aiImages[] = $image;
        }

        return ['identifier' => $identifier, 'notes' => $notes, 'uploads' => $uploads, 'ai_images' => $aiImages];
    }

    /**
     * Asks the AI for every field. Saves nothing.
     *
     * @param array{identifier: string, notes: string, ai_images: list<array{mime: string, data: string}>} $input
     * @return array{name: string, category: string, short_description: string, long_description: string, weight_grams: int, variants: list<array{label: ?string, unit: ?string, price: float, stock: int}>}
     */
    public function generate(array $input): array
    {
        $reply = $this->gemini->generateWithImages($this->prompt($input), $input['ai_images'], true, 90);
        $data = $this->parse($reply);
        if ($data === null) {
            $this->logger->warning('Magic add: unreadable AI reply', ['reply' => mb_substr($reply, 0, 2000)]);
            throw new RuntimeException($this->t('The AI reply could not be read. Try again.'));
        }
        if ($data['name'] === '') {
            $data['name'] = mb_substr($input['identifier'], 0, ProductController::MAX_NAME);
        }

        return $data;
    }

    /**
     * Creates the product (status "created"), its variants and photos.
     *
     * @param array{name: string, category: string, short_description: string, long_description: string, weight_grams: int, variants: list<array{label: ?string, unit: ?string, price: float, stock: int}>} $data
     * @param list<UploadedFileInterface> $uploads
     * @return array{id: int, name: string, variants: int, warnings: list<string>}
     */
    public function save(array $data, array $uploads): array
    {
        $warnings = [];
        $categoryId = $this->categoryId($data['category']);

        $variants = [];
        $usedSkus = [];
        foreach ($data['variants'] as $variant) {
            $sku = $this->uniqueSku($data['name'], $variant['label'], $usedSkus);
            $usedSkus[] = $sku;
            $variants[] = [
                'sku'          => $sku,
                'label'        => $variant['label'],
                'unit'         => $variant['unit'],
                'price'        => $variant['price'],
                'stock'        => $variant['stock'],
                'weight_grams' => $data['weight_grams'],
            ];
        }
        if (array_filter($variants, static fn (array $v) => $v['price'] <= 0) !== []) {
            $warnings[] = $this->t('No price was found for some variants — set one before approving.');
        }

        $productId = $this->db->transaction(function () use ($data, $categoryId, $variants): int {
            $productId = $this->products->create([
                'category_id'       => $categoryId,
                'name'              => $data['name'],
                'short_description' => $data['short_description'],
                'long_description'  => $data['long_description'],
                'import_status'     => 'created',
                'is_active'         => 1,
            ]);
            foreach ($variants as $variant) {
                $this->variants->create(['product_id' => $productId] + $variant);
            }

            return $productId;
        });

        foreach ($uploads as $upload) {
            try {
                $this->images->addUpload($productId, $upload);
            } catch (Throwable $e) {
                $warnings[] = $e->getMessage();
            }
        }

        return ['id' => $productId, 'name' => $data['name'], 'variants' => count($variants), 'warnings' => $warnings];
    }

    /** @param array{identifier: string, notes: string, ai_images: list<array{mime: string, data: string}>} $input */
    private function prompt(array $input): string
    {
        $categories = implode(', ', array_map(static fn (array $c) => (string) $c['name'], $this->categories->findAll())) ?: '(none yet)';
        $language = $this->context->languageName();
        $currency = $this->store->currency();
        $photos = count($input['ai_images']);
        $notes = $input['notes'] !== '' ? $input['notes'] : '(none)';
        $maxName = ProductController::MAX_NAME;
        $maxShort = ProductController::MAX_SHORT_DESCRIPTION;

        $override = $this->prompts->override(PromptLibrary::MAGIC_ADD, [
            'identifier' => $input['identifier'],
            'notes'      => $notes,
            'photos'     => (string) $photos,
            'categories' => $categories,
            'language'   => $language,
            'currency'   => $currency,
            'store'      => $this->store->name(),
        ]);
        if ($override !== null) {
            return $override;
        }

        return <<<PROMPT
            You are creating a new product listing for the online store "{$this->store->name()}".
            The store owner only gave you the following. Write everything else.

            Product name / core identifier: {$input['identifier']}
            Photos attached: {$photos}
            Key specs / raw notes / prices (unformatted shorthand):
            {$notes}

            Write the listing in {$language}. Prices are in {$currency}.
            Existing categories: {$categories}

            Rules:
            - name: a clear storefront name built on the identifier (keep species / model names exact), at most {$maxName} characters, plain text.
            - category: the best matching existing category name, copied exactly; only if none fits, a short new category name.
            - short_description: one or two sentences, at most {$maxShort} characters, plain text.
            - long_description: HTML using only p, ul, ol, li, strong, em. Describe the product and turn the notes into
              readable specs or care/usage details. Use what the photos show (colour, size, condition, pot, packaging).
              Well-known facts about the species or model are fine; never invent specs, certifications, guarantees or claims
              that are not in the notes, the photos, or common knowledge.
            - variants: one entry per price in the notes. If the notes list several options with their own price
              (e.g. "white-12.50eur, black-13.50"), make one variant per option with label = the option ("White", "Black").
              With a single price, one variant with label null. If no price is given, one variant with price null.
              unit: the selling unit if stated (e.g. "kg", "pack of 6"), else null. stock: the quantity if the notes say how
              many are available, else null. Never invent a price.
            - weight_grams: the shipping weight in grams if stated or obvious from the notes, else null.

            Reply with only this JSON object:
            {"name": "", "category": "", "short_description": "", "long_description": "",
             "variants": [{"label": null, "unit": null, "price": 0.00, "stock": null}], "weight_grams": null}
            PROMPT;
    }

    /**
     * @return array{name: string, category: string, short_description: string, long_description: string, weight_grams: int, variants: list<array{label: ?string, unit: ?string, price: float, stock: int}>}|null
     */
    private function parse(string $reply): ?array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            return null;
        }

        $text = static fn (mixed $v, int $max): string => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(is_scalar($v) ? (string) $v : '')) ?? ''), 0, $max);

        $short = $text($decoded['short_description'] ?? '', ProductController::MAX_SHORT_DESCRIPTION);
        $long = $this->sanitizer->clean(is_string($decoded['long_description'] ?? null) ? $decoded['long_description'] : '');
        if (strlen($long) > ProductController::MAX_LONG_DESCRIPTION_BYTES) {
            $long = $this->sanitizer->clean(mb_strcut($long, 0, ProductController::MAX_LONG_DESCRIPTION_BYTES - 200));
        }
        if ($short === '' || trim(strip_tags($long)) === '') {
            return null;
        }

        $variants = [];
        foreach (is_array($decoded['variants'] ?? null) ? $decoded['variants'] : [] as $row) {
            if (!is_array($row) || count($variants) >= self::MAX_VARIANTS) {
                continue;
            }
            $label = $text($row['label'] ?? '', 64);
            $unit = $text($row['unit'] ?? '', 64);
            $variants[] = [
                'label' => $label !== '' && strtolower($label) !== 'null' ? $label : null,
                'unit'  => $unit !== '' && strtolower($unit) !== 'null' ? $unit : null,
                'price' => self::price($row['price'] ?? null),
                'stock' => is_numeric($row['stock'] ?? null) ? (int) min(4294967295, max(0, (int) $row['stock'])) : 0,
            ];
        }
        if ($variants === []) {
            $variants[] = ['label' => null, 'unit' => null, 'price' => 0.0, 'stock' => 0];
        }

        return [
            'name'              => $text($decoded['name'] ?? '', ProductController::MAX_NAME),
            'category'          => $text($decoded['category'] ?? '', 80),
            'short_description' => $short,
            'long_description'  => $long,
            'weight_grams'      => is_numeric($decoded['weight_grams'] ?? null) ? (int) min(4294967295, max(0, (int) $decoded['weight_grams'])) : 0,
            'variants'          => $variants,
        ];
    }

    /** "12.50", 12.5, "12,50 €" → 12.5; anything unreadable → 0. */
    private static function price(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return round(max(0.0, min((float) $value, VariantRepository::MAX_PRICE)), 2);
        }
        if (is_string($value) && preg_match('/\d+(?:[.,]\d{1,2})?/', $value, $m)) {
            return round(max(0.0, min((float) str_replace(',', '.', $m[0]), VariantRepository::MAX_PRICE)), 2);
        }

        return 0.0;
    }

    /** The existing category with that name or slug, else a new one (or the first one when the AI gave none). */
    private function categoryId(string $name): int
    {
        $all = $this->categories->findAll();
        if ($name !== '') {
            $slug = Slug::from($name);
            foreach ($all as $category) {
                if (mb_strtolower((string) $category['name']) === mb_strtolower($name) || (string) $category['slug'] === $slug) {
                    return (int) $category['id'];
                }
            }

            return $this->categories->create($name);
        }
        if ($all !== []) {
            return (int) $all[0]['id'];
        }

        return $this->categories->create($this->t('Other'));
    }

    /** @param list<string> $taken SKUs already chosen for this product */
    private function uniqueSku(string $name, ?string $label, array $taken): string
    {
        $base = strtoupper(Slug::from($name, 'product'));
        $base = rtrim(substr($base, 0, 40), '-');
        if ($label !== null) {
            $base .= '-' . rtrim(substr(strtoupper(Slug::from($label, 'v')), 0, 18), '-');
        }
        $sku = $base;
        for ($i = 2; in_array($sku, $taken, true) || $this->variants->skuExists($sku); $i++) {
            $sku = $base . '-' . $i;
        }

        return $sku;
    }

    /** A JPEG of at most 1024 px a side for the AI (photos are often 4000 px), or null if it isn't an image. */
    private function forAi(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }
        [$width, $height] = $info;
        if (!function_exists('imagecreatefromstring') || max($width, $height) <= self::AI_IMAGE_SIDE) {
            return ['mime' => $info['mime'], 'data' => $bytes];
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return ['mime' => $info['mime'], 'data' => $bytes];
        }
        $scale = self::AI_IMAGE_SIDE / max($width, $height);
        $copy = imagescale($source, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagedestroy($source);
        if ($copy === false) {
            return ['mime' => $info['mime'], 'data' => $bytes];
        }
        ob_start();
        imagejpeg($copy, null, 85);
        imagedestroy($copy);

        return ['mime' => 'image/jpeg', 'data' => (string) ob_get_clean()];
    }

    /** @param array<string, mixed> $params */
    private function t(string $message, array $params = []): string
    {
        return $this->translator->trans($message, $params);
    }
}
