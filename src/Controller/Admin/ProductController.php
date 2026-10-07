<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\Infrastructure\Database;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;
use App\Security\HtmlSanitizer;
use App\Service\ProductImageManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Admin → Products: list, filter, sort, edit, bulk actions, images and creation. */
final class ProductController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly CategoryRepository $categories,
        private readonly ProductImageManager $images,
        private readonly HtmlSanitizer $sanitizer,
        private readonly LoggerInterface $logger,
    ) {
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
                    $this->session->flash('success', 'Status updated.');
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
                    $this->session->flash('error', 'No products selected or invalid status.');
                } else {
                    $count = $this->products->setStatus($ids, $status);
                    $this->session->flash('success', "Updated status for {$count} product(s).");
                }
                break;

            case 'update':
                if ($id > 0) {
                    $this->products->update($id, [
                        'name'              => trim((string) ($body['name'] ?? '')),
                        'category_id'       => (int) ($body['category_id'] ?? 0),
                        'short_description' => trim((string) ($body['short_description'] ?? '')),
                        'long_description'  => $this->sanitizer->clean((string) ($body['long_description'] ?? '')),
                    ]);
                    $this->session->flash('success', 'Product updated.');
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
                    $this->session->flash('success', 'Image removed.');
                    $editId = $id;
                }
                break;

            case 'set_main_image':
                if ($id > 0) {
                    $this->images->setMain($id, (string) ($body['path'] ?? ''));
                    $this->session->flash('success', 'Main image updated.');
                    $editId = $id;
                }
                break;

            case 'create':
                return $this->create($request, $body);
        }

        return $this->responder->redirectToRoute('admin.products', [], $editId !== null ? ['edit' => $editId] : []);
    }

    /** @param array<string, mixed> $body */
    private function create(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $data = [
            'name'              => trim((string) ($body['name'] ?? '')),
            'category_id'       => (int) ($body['category_id'] ?? 0),
            'short_description' => trim((string) ($body['short_description'] ?? '')),
            'long_description'  => $this->sanitizer->clean((string) ($body['long_description'] ?? '')),
        ];
        $variant = [
            'sku'   => trim((string) ($body['sku'] ?? '')),
            'label' => trim((string) ($body['label'] ?? '')) ?: null,
            'unit'  => trim((string) ($body['unit'] ?? '')) ?: null,
            'price' => (float) ($body['price'] ?? 0),
            'stock' => max(0, (int) ($body['stock'] ?? 0)),
        ];

        $complete = $data['name'] !== '' && $data['category_id'] > 0 && $data['short_description'] !== ''
            && trim(strip_tags($data['long_description'])) !== '' && $variant['sku'] !== '' && $variant['price'] > 0;
        if (!$complete) {
            return $this->page($request, ['Please fill in all required fields.'], $body, 422);
        }

        try {
            $this->db->transaction(function () use ($data, $variant): void {
                $productId = $this->products->create($data);
                $this->variants->create(['product_id' => $productId] + $variant);
            });
        } catch (Throwable $e) {
            $this->logger->warning('Product creation failed', ['exception' => $e]);

            return $this->page($request, ['Could not create the product: ' . $e->getMessage()], $body, 422);
        }

        $this->session->flash('success', "Product \"{$data['name']}\" created.");

        return $this->responder->redirectToRoute('admin.products');
    }

    /** @param list<int> $ids */
    private function deleteProducts(array $ids): void
    {
        $ids = array_values(array_filter($ids, static fn (int $id) => $id > 0));
        if ($ids === []) {
            $this->session->flash('error', 'No products selected.');

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
                ? $this->session->flash('success', 'Product deleted.')
                : $this->session->flash('error', 'Could not delete: it is referenced by existing orders.');

            return;
        }

        $message = "Deleted {$deleted} product(s).";
        if ($failed > 0) {
            $message .= " {$failed} could not be deleted (referenced by existing orders).";
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

        $message = $uploaded > 0 ? "Uploaded {$uploaded} image(s)." : 'No images were uploaded.';
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
     * @param list<string>              $errors
     * @param array<string, mixed>|null $createInput posted "add product" values to show again
     */
    private function page(ServerRequestInterface $request, array $errors = [], ?array $createInput = null, int $status = 200): ResponseInterface
    {
        $query = $request->getQueryParams();

        $statusFilter = (string) ($query['status'] ?? '');
        $statusFilter = in_array($statusFilter, ProductRepository::STATUSES, true) ? $statusFilter : null;

        $sort = (string) ($query['sort'] ?? '');
        $sort = isset(ProductRepository::ADMIN_SORT_COLUMNS[$sort]) ? $sort : null;
        $dir = strtolower((string) ($query['dir'] ?? '')) === 'desc' ? 'desc' : 'asc';

        $total = $this->products->countForAdmin($statusFilter);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, (int) ($query['page'] ?? 1)));

        $products = $this->products->pageForAdmin($statusFilter, $sort, $dir, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        foreach ($products as &$product) {
            $product['images'] = $this->imageList($product);
            $product['long_description_html'] = $this->sanitizer->clean((string) ($product['long_description'] ?? ''));
        }
        unset($product);

        if ($createInput !== null) {
            $createInput['long_description'] = $this->sanitizer->clean((string) ($createInput['long_description'] ?? ''));
        }

        return $this->responder->view($request, 'admin/products.html.twig', [
            'errors'        => $errors,
            'create_input'  => $createInput,
            'categories'    => $this->categories->findAll(),
            'statuses'      => ProductRepository::STATUSES,
            'status_filter' => $statusFilter,
            'sort'          => $sort,
            'dir'           => $dir,
            'products'      => $products,
            'expanded_id'   => (int) ($query['edit'] ?? 0),
            'variants'      => $this->variants->findForProducts(array_map('intval', array_column($products, 'id'))),
            'page'          => $page,
            'total_pages'   => $totalPages,
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
