<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\I18n\Translator;
use App\Http\Session;
use App\Infrastructure\Database;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;
use App\Security\HtmlSanitizer;
use App\Service\ProductImageManager;
use App\Service\StoreSettings;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin → Products: list, filter, sort, edit, bulk actions, images and creation.
 *
 * Every form posts to admin.products.submit with the list's query string
 * (page, sort, dir, status, stock), so the redirect lands on the same view.
 */
final class ProductController
{
    private const PER_PAGE = 50;

    /** Column sizes from database/schema.sql. */
    private const MAX_NAME = 180;
    private const MAX_SHORT_DESCRIPTION = 280;
    private const MAX_LONG_DESCRIPTION_BYTES = 65535;
    private const MAX_VARIANT_FIELD = 64;

    /** MySQL/MariaDB driver error codes (PDOException::$errorInfo[1]). */
    private const ER_DUP_ENTRY = 1062;
    private const ER_ROW_IS_REFERENCED = 1451;
    private const ER_NO_REFERENCED_ROW = 1452;
    private const ER_DATA_TOO_LONG = 1406;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly CategoryRepository $categories,
        private readonly ProductImageManager $images,
        private readonly HtmlSanitizer $sanitizer,
        private readonly StoreSettings $store,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, mixed> $params */
    private function t(string $message, array $params = []): string
    {
        return $this->translator->trans($message, $params);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $id = (int) ($body['id'] ?? 0);
        $editId = null;

        switch ($action) {
            case 'set_status':
                $status = (string) ($body['status'] ?? '');
                if ($id > 0 && in_array($status, ProductRepository::STATUSES, true)) {
                    $this->products->setStatus([$id], $status);
                    $this->session->flash('success', $this->t('Status updated.'));
                }
                break;

            case 'delete':
                $this->deleteProducts([$id]);
                break;

            case 'bulk_delete':
                $this->deleteProducts($this->selectedIds($body));
                break;

            case 'bulk_set_status':
                $ids = $this->selectedIds($body);
                $status = (string) ($body['status'] ?? '');
                if ($ids === [] || !in_array($status, ProductRepository::STATUSES, true)) {
                    $this->session->flash('error', $this->t('No products selected or invalid status.'));
                } else {
                    $count = $this->products->setStatus($ids, $status);
                    $this->session->flash('success', $this->translator->transPlural('Updated status for {count} product.', $count));
                }
                break;

            case 'bulk_price':
                $this->bulkPrice($this->selectedIds($body), $body);
                break;

            case 'update':
                if ($id > 0) {
                    $errors = $this->updateProduct($id, $body);
                    if ($errors !== []) {
                        return $this->page($request, $errors, null, 422, ['id' => $id] + $body);
                    }
                    $editId = $id;
                }
                break;

            case 'update_variant':
                if ($id > 0) {
                    $this->updateVariant($id, (int) ($body['variant_id'] ?? 0), $body);
                    $editId = $id;
                }
                break;

            case 'add_variant':
                if ($id > 0) {
                    $this->addVariant($id, $body);
                    $editId = $id;
                }
                break;

            case 'delete_variant':
                if ($id > 0) {
                    $this->deleteVariant($id, (int) ($body['variant_id'] ?? 0));
                    $editId = $id;
                }
                break;

            case 'upload_images':
                if ($id > 0) {
                    $this->uploadImages($id, $request->getUploadedFiles()['images'] ?? []);
                    $editId = $id;
                }
                break;

            case 'delete_image':
                if ($id > 0) {
                    $this->images->remove($id, (string) ($body['path'] ?? ''));
                    $this->session->flash('success', $this->t('Image removed.'));
                    $editId = $id;
                }
                break;

            case 'set_main_image':
                if ($id > 0) {
                    $this->images->setMain($id, (string) ($body['path'] ?? ''));
                    $this->session->flash('success', $this->t('Main image updated.'));
                    $editId = $id;
                }
                break;

            case 'move_image':
                if ($id > 0) {
                    $this->images->move($id, (string) ($body['path'] ?? ''), (int) ($body['offset'] ?? 0) < 0 ? -1 : 1);
                    $this->session->flash('success', $this->t('Image order updated.'));
                    $editId = $id;
                }
                break;

            case 'reorder_images':
                if ($id > 0) {
                    $order = array_values(array_filter((array) ($body['order'] ?? []), 'is_string'));
                    $this->images->reorder($id, $order);
                    $this->session->flash('success', $this->t('Image order updated.'));
                    $editId = $id;
                }
                break;

            case 'create':
                return $this->create($request, $body);
        }

        $query = $this->listQuery($request->getQueryParams());
        if ($editId !== null) {
            $query['edit'] = $editId;
        }

        return $this->responder->redirectToRoute('admin.products', [], $query);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string> validation or save errors (empty on success)
     */
    private function updateProduct(int $id, array $body): array
    {
        $data = $this->productInput($body);
        $errors = $this->validateProduct($data, false);
        if ($errors !== []) {
            return $errors;
        }

        try {
            $this->products->update($id, $data);
        } catch (PDOException $e) {
            $this->logger->warning('Product update failed', ['product_id' => $id, 'exception' => $e]);

            return [match ($this->driverCode($e)) {
                self::ER_NO_REFERENCED_ROW => $this->t('That category no longer exists — choose another one.'),
                self::ER_DATA_TOO_LONG     => $this->t('One of the fields is too long.'),
                default                    => $this->t('Could not save the product because of a database error. Please try again.'),
            }];
        }

        $this->session->flash('success', $this->t('Product updated.'));

        return [];
    }

    /** @param array<string, mixed> $body */
    private function create(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $data = $this->productInput($body);
        $variant = [
            'sku'   => trim((string) ($body['sku'] ?? '')),
            'label' => trim((string) ($body['label'] ?? '')) ?: null,
            'unit'  => trim((string) ($body['unit'] ?? '')) ?: null,
            'price' => round((float) ($body['price'] ?? 0), 2),
            'stock' => max(0, (int) ($body['stock'] ?? 0)),
        ];

        $errors = $this->validateProduct($data, true);
        if ($variant['price'] <= 0) {
            $errors[] = $this->t('The first variant needs a price above 0.');
        }
        $variantError = $this->validateVariant($variant);
        if ($variantError !== null) {
            $errors[] = $variantError;
        }
        if ($errors !== []) {
            return $this->page($request, $errors, $body, 422);
        }

        try {
            $this->db->transaction(function () use ($data, $variant): void {
                $productId = $this->products->create($data);
                $this->variants->create(['product_id' => $productId] + $variant);
            });
        } catch (PDOException $e) {
            $this->logger->warning('Product creation failed', ['exception' => $e]);

            return $this->page($request, [$this->t('Could not create the product: {reason}', ['reason' => $this->variantError($e, $variant['sku'])])], $body, 422);
        } catch (Throwable $e) {
            $this->logger->warning('Product creation failed', ['exception' => $e]);

            return $this->page($request, [$this->t('Could not create the product. Please try again.')], $body, 422);
        }

        $this->session->flash('success', $this->t('Product "{name}" created.', ['name' => $data['name']]));

        return $this->responder->redirectToRoute('admin.products', [], $this->listQuery($request->getQueryParams()));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{name: string, category_id: int, short_description: string, long_description: string}
     */
    private function productInput(array $body): array
    {
        return [
            'name'              => trim((string) ($body['name'] ?? '')),
            'category_id'       => (int) ($body['category_id'] ?? 0),
            'short_description' => trim((string) ($body['short_description'] ?? '')),
            'long_description'  => $this->sanitizer->clean((string) ($body['long_description'] ?? '')),
        ];
    }

    /**
     * @param array{name: string, category_id: int, short_description: string, long_description: string} $data
     * @return list<string>
     */
    private function validateProduct(array $data, bool $requireLongDescription): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors[] = $this->t('Name is required.');
        } elseif (mb_strlen($data['name']) > self::MAX_NAME) {
            $errors[] = $this->t('Name can be at most {max} characters.', ['max' => self::MAX_NAME]);
        }
        if ($data['category_id'] <= 0 || !in_array($data['category_id'], array_map('intval', array_column($this->categories->findAll(), 'id')), true)) {
            $errors[] = $this->t('Please choose a category.');
        }
        if ($data['short_description'] === '') {
            $errors[] = $this->t('Short description is required.');
        } elseif (mb_strlen($data['short_description']) > self::MAX_SHORT_DESCRIPTION) {
            $errors[] = $this->t('Short description can be at most {max} characters.', ['max' => self::MAX_SHORT_DESCRIPTION]);
        }
        if ($requireLongDescription && trim(strip_tags($data['long_description'])) === '') {
            $errors[] = $this->t('Long description is required.');
        }
        if (strlen($data['long_description']) > self::MAX_LONG_DESCRIPTION_BYTES) {
            $errors[] = $this->t('Long description is too long.');
        }

        return $errors;
    }

    /** @param array<string, mixed> $body */
    private function addVariant(int $productId, array $body): void
    {
        $data = $this->variantInput($body);
        $error = $this->validateVariant($data);
        if ($error !== null) {
            $this->session->flash('error', $error);

            return;
        }

        try {
            $this->variants->create(['product_id' => $productId] + $data);
            $this->session->flash('success', $this->t('Variant {sku} added.', ['sku' => $data['sku']]));
        } catch (PDOException $e) {
            $this->logger->warning('Variant creation failed', ['exception' => $e]);
            $this->session->flash('error', $this->t('Could not add the variant: {reason}', ['reason' => $this->variantError($e, $data['sku'])]));
        }
    }

    /** @param array<string, mixed> $body */
    private function updateVariant(int $productId, int $variantId, array $body): void
    {
        $data = $this->variantInput($body);
        $error = $variantId > 0 ? $this->validateVariant($data) : $this->t('Variant not found.');
        if ($error !== null) {
            $this->session->flash('error', $error);

            return;
        }

        try {
            $this->variants->update($variantId, $productId, $data)
                ? $this->session->flash('success', $this->t('Variant {sku} updated.', ['sku' => $data['sku']]))
                : $this->session->flash('error', $this->t('Variant not found — it may have been deleted. Reload the page.'));
        } catch (PDOException $e) {
            $this->logger->warning('Variant update failed', ['exception' => $e]);
            $this->session->flash('error', $this->t('Could not update the variant: {reason}', ['reason' => $this->variantError($e, $data['sku'])]));
        }
    }

    private function deleteVariant(int $productId, int $variantId): void
    {
        try {
            $deleted = $variantId > 0 && $this->variants->delete($variantId, $productId);
            $deleted
                ? $this->session->flash('success', $this->t('Variant deleted.'))
                : $this->session->flash('error', $this->t('Variant not found.'));
        } catch (PDOException $e) {
            if ($this->driverCode($e) === self::ER_ROW_IS_REFERENCED) {
                $this->session->flash('error', $this->t('Could not delete: this variant is referenced by existing orders. Untick "Active" to hide it instead.'));

                return;
            }
            $this->logger->warning('Variant deletion failed', ['exception' => $e]);
            $this->session->flash('error', $this->t('Could not delete the variant because of a database error. Please try again.'));
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{sku: string, label: ?string, unit: ?string, price: float, stock: int, is_active: int}
     */
    private function variantInput(array $body): array
    {
        return [
            'sku'       => trim((string) ($body['sku'] ?? '')),
            'label'     => trim((string) ($body['label'] ?? '')) ?: null,
            'unit'      => trim((string) ($body['unit'] ?? '')) ?: null,
            'price'     => round(max(0.0, (float) ($body['price'] ?? 0)), 2),
            'stock'     => max(0, (int) ($body['stock'] ?? 0)),
            'is_active' => empty($body['is_active']) ? 0 : 1,
        ];
    }

    /** @param array{sku: string, label: ?string, unit: ?string, price: float, stock: int} $data */
    private function validateVariant(array $data): ?string
    {
        return match (true) {
            $data['sku'] === ''                                                => $this->t('Variant SKU is required.'),
            mb_strlen($data['sku']) > self::MAX_VARIANT_FIELD                  => $this->t('SKU can be at most {max} characters.', ['max' => self::MAX_VARIANT_FIELD]),
            mb_strlen((string) $data['label']) > self::MAX_VARIANT_FIELD,
            mb_strlen((string) $data['unit']) > self::MAX_VARIANT_FIELD        => $this->t('Label and unit can be at most {max} characters.', ['max' => self::MAX_VARIANT_FIELD]),
            $data['price'] > VariantRepository::MAX_PRICE                      => $this->t('That price is too high.'),
            $data['stock'] > 4294967295                                        => $this->t('That stock level is too high.'),
            default                                                            => null,
        };
    }

    /** User-facing reason a variant insert/update failed. */
    private function variantError(PDOException $e, string $sku): string
    {
        return match ($this->driverCode($e)) {
            self::ER_DUP_ENTRY         => $this->t('SKU "{sku}" is already used by another variant.', ['sku' => $sku]),
            self::ER_ROW_IS_REFERENCED => $this->t('it is referenced by existing orders.'),
            self::ER_NO_REFERENCED_ROW => $this->t('the product or category no longer exists.'),
            self::ER_DATA_TOO_LONG     => $this->t('one of the fields is too long.'),
            default                    => $this->t('a database error occurred. Please try again.'),
        };
    }

    private function driverCode(PDOException $e): int
    {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    /**
     * Sets, raises or lowers the price of every variant of the selected
     * products (by a percentage or a fixed amount; never below 0).
     *
     * @param list<int>            $ids
     * @param array<string, mixed> $body
     */
    private function bulkPrice(array $ids, array $body): void
    {
        $mode = (string) ($body['price_mode'] ?? '');
        $value = filter_var(str_replace(',', '.', trim((string) ($body['price_value'] ?? ''))), FILTER_VALIDATE_FLOAT);

        $error = match (true) {
            $ids === []                                                        => $this->t('No products selected.'),
            !in_array($mode, VariantRepository::PRICE_MODES, true)              => $this->t('Choose how to change the price.'),
            $value === false || !is_finite($value) || $value < 0               => $this->t('Enter a price change of 0 or more.'),
            $mode === 'decrease_percent' && $value > 100                       => $this->t('A price can be lowered by at most 100%.'),
            $value > VariantRepository::MAX_PRICE                              => $this->t('That amount is too high.'),
            default                                                            => null,
        };
        if ($error !== null) {
            $this->session->flash('error', $error);

            return;
        }

        $isPercent = str_ends_with($mode, '_percent');
        $count = $this->variants->adjustPricesForProducts($ids, $mode, $isPercent ? (float) $value : round((float) $value, 2));
        $this->session->flash('success', $this->translator->transPlural('Updated the price of {count} variant in {products}.', $count, ['products' => $this->translator->transPlural('{count} product', count($ids))]));
    }

    /** @param list<int> $ids */
    private function deleteProducts(array $ids): void
    {
        $ids = array_values(array_filter($ids, static fn (int $id) => $id > 0));
        if ($ids === []) {
            $this->session->flash('error', $this->t('No products selected.'));

            return;
        }

        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $this->images->deleteProduct($id);
                $deleted++;
            } catch (Throwable) {
                // Orders still reference its variants.
            }
        }
        $failed = count($ids) - $deleted;

        if (count($ids) === 1) {
            $failed === 0
                ? $this->session->flash('success', $this->t('Product deleted.'))
                : $this->session->flash('error', $this->t('Could not delete: it is referenced by existing orders.'));

            return;
        }

        $message = $this->translator->transPlural('Deleted {count} product.', $deleted);
        if ($failed > 0) {
            $message .= ' ' . $this->translator->transPlural('{count} could not be deleted (referenced by existing orders).', $failed);
        }
        $this->session->flash($deleted > 0 ? 'success' : 'error', $message);
    }

    /** @param UploadedFileInterface|array<mixed> $files */
    private function uploadImages(int $productId, UploadedFileInterface|array $files): void
    {
        $uploaded = 0;
        $errors = [];
        foreach (is_array($files) ? $files : [$files] as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $this->images->addUpload($productId, $file);
                $uploaded++;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $message = $uploaded > 0 ? $this->translator->transPlural('Uploaded {count} image.', $uploaded) : $this->t('No images were uploaded.');
        if ($errors !== []) {
            $message .= ' ' . implode(' ', $errors);
        }
        $this->session->flash($uploaded > 0 ? 'success' : 'error', $message);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<int>
     */
    private function selectedIds(array $body): array
    {
        $ids = array_map('intval', (array) ($body['ids'] ?? []));

        return array_values(array_unique(array_filter($ids, static fn (int $id) => $id > 0)));
    }

    /**
     * The list view's state (filters, sort, page) from a query string,
     * validated, without empty values. Used for links, form actions and the
     * redirect after a POST.
     *
     * @param array<string, mixed> $query
     * @return array{status?: string, stock?: string, sort?: string, dir?: string, page?: int}
     */
    private function listQuery(array $query): array
    {
        $state = [];
        $status = (string) ($query['status'] ?? '');
        if (in_array($status, ProductRepository::STATUSES, true)) {
            $state['status'] = $status;
        }
        if (($query['stock'] ?? '') === 'low') {
            $state['stock'] = 'low';
        }
        $sort = (string) ($query['sort'] ?? '');
        if (isset(ProductRepository::ADMIN_SORT_COLUMNS[$sort])) {
            $state['sort'] = $sort;
            $state['dir'] = strtolower((string) ($query['dir'] ?? '')) === 'desc' ? 'desc' : 'asc';
        }
        $page = (int) ($query['page'] ?? 1);
        if ($page > 1) {
            $state['page'] = $page;
        }

        return $state;
    }

    /**
     * @param list<string>              $errors
     * @param array<string, mixed>|null $createInput posted "add product" values to show again
     * @param array<string, mixed>|null $editInput   posted edit-form values (with 'id') to show again
     */
    private function page(ServerRequestInterface $request, array $errors = [], ?array $createInput = null, int $status = 200, ?array $editInput = null): ResponseInterface
    {
        $query = $request->getQueryParams();
        $listQuery = $this->listQuery($query);

        $statusFilter = $listQuery['status'] ?? null;
        $sort = $listQuery['sort'] ?? null;
        $dir = $listQuery['dir'] ?? 'asc';
        $lowStockOnly = isset($listQuery['stock']);
        $threshold = $this->store->lowStockThreshold();

        $total = $this->products->countForAdmin($statusFilter, $lowStockOnly ? $threshold : null);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, $listQuery['page'] ?? 1));
        if ($page > 1) {
            $listQuery['page'] = $page;
        } else {
            unset($listQuery['page']);
        }

        $products = $this->products->pageForAdmin($statusFilter, $sort, $dir, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $lowStockOnly ? $threshold : null);
        $variants = $this->variants->findForProducts(array_map('intval', array_column($products, 'id')));
        foreach ($products as &$product) {
            $product['images'] = $this->imageList($product);
            $product['long_description_html'] = $this->sanitizer->clean((string) ($product['long_description'] ?? ''));
            $product['low_stock_count'] = count(array_filter(
                $variants[(int) $product['id']] ?? [],
                static fn (array $v) => (int) $v['is_active'] === 1 && (int) $v['stock'] <= $threshold
            ));
        }
        unset($product);

        if ($createInput !== null) {
            $createInput['long_description'] = $this->sanitizer->clean((string) ($createInput['long_description'] ?? ''));
        }
        if ($editInput !== null) {
            $editInput['id'] = (int) ($editInput['id'] ?? 0);
            $editInput['category_id'] = (int) ($editInput['category_id'] ?? 0);
            $editInput['long_description'] = $this->sanitizer->clean((string) ($editInput['long_description'] ?? ''));
        }

        return $this->responder->view($request, 'admin/products.html.twig', [
            'errors'              => $errors,
            'create_input'        => $createInput,
            'edit_input'          => $editInput,
            'categories'          => $this->categories->findAll(),
            'statuses'            => ProductRepository::STATUSES,
            'status_filter'       => $statusFilter,
            'low_stock_only'      => $lowStockOnly,
            'low_stock_threshold' => $threshold,
            'sort'                => $sort,
            'dir'                 => $dir,
            'list_query'          => $listQuery,
            'products'            => $products,
            'expanded_id'         => $editInput['id'] ?? (int) ($query['edit'] ?? 0),
            'variants'            => $variants,
            'page'                => $page,
            'total_pages'         => $totalPages,
            'price_modes'         => [
                'set'              => $this->t('Set price to'),
                'increase_percent' => $this->t('Increase by %'),
                'decrease_percent' => $this->t('Decrease by %'),
                'increase_amount'  => $this->t('Increase by amount'),
                'decrease_amount'  => $this->t('Decrease by amount'),
            ],
            'limits'              => [
                'name'              => self::MAX_NAME,
                'short_description' => self::MAX_SHORT_DESCRIPTION,
                'variant_field'     => self::MAX_VARIANT_FIELD,
            ],
        ], $status);
    }

    /**
     * @param array<string, mixed> $product
     * @return list<string> image paths, main first (falls back to image_path for older rows)
     */
    private function imageList(array $product): array
    {
        $decoded = json_decode((string) ($product['images'] ?? ''), true);
        $images = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        if ($images === [] && !empty($product['image_path'])) {
            $images = [(string) $product['image_path']];
        }

        return $images;
    }
}
