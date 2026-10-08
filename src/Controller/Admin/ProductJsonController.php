<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\CsvDownload;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ProductRepository;
use App\Service\ProductJsonExporter;
use App\Service\ProductJsonImporter;
use App\Service\StoreSettings;
use App\Support\Paths;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Admin → Products → Export JSON / Import JSON.
 *
 * Import is two steps, like the CSV one: the upload is checked and shown as a
 * preview (what each product would create or change, which images would be
 * downloaded) without writing anything; "Apply" then re-checks the same file
 * and writes it, fetching the images that aren't stored locally. Between the
 * steps the file waits in var/tmp/imports/ under a random name remembered in
 * the session. Large catalogs with many images are better imported with
 * `bin/console products:import`, which isn't bound by a request time limit.
 */
final class ProductJsonController
{
    private const SESSION_KEY = 'product_json_import';
    private const STALE_SECONDS = 86400;
    /** Preview rows shown (the counts always cover the whole file). */
    private const PREVIEW_ROWS = 300;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CsvDownload $download,
        private readonly ProductJsonExporter $exporter,
        private readonly ProductJsonImporter $importer,
        private readonly StoreSettings $store,
        private readonly Paths $paths,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
    ) {
    }

    /** Same filters as the product list (?status=…&stock=low); ?embed_images=1 adds the image files. */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = (string) ($query['status'] ?? '');
        $status = in_array($status, ProductRepository::STATUSES, true) ? $status : null;
        $lowStock = ($query['stock'] ?? '') === 'low' ? $this->store->lowStockThreshold() : null;

        @set_time_limit(0);
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException($this->translator->trans('Could not create the export.'));
        }
        $this->exporter->write($handle, $status, $lowStock, !empty($query['embed_images']));

        return $this->download->response($handle, 'products-' . date('Ymd-His') . '.json', 'application/json; charset=utf-8');
    }

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        try {
            return match ((string) ($body['action'] ?? '')) {
                'preview' => $this->preview($request, $body),
                'apply'   => $this->apply($request, $body),
                'cancel'  => $this->cancel(),
                default   => throw new RuntimeException($this->translator->trans('Unknown action.')),
            };
        } catch (PDOException $e) {
            $this->logger->warning('Product JSON import failed', ['exception' => $e]);

            return $this->page($request, [$this->translator->trans('A database error occurred — nothing was changed. Please try again.')], 500);
        } catch (RuntimeException $e) {
            return $this->page($request, [$e->getMessage()], 422);
        }
    }

    /** @param array<string, mixed> $body */
    private function preview(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $file = $request->getUploadedFiles()['json'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException($this->translator->trans('Choose a JSON file to import.'));
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->translator->trans(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The upload failed — the file is too large.' : 'The upload failed.'));
        }
        if ((int) $file->getSize() > ProductJsonImporter::MAX_BYTES) {
            throw new RuntimeException($this->translator->trans('The file is too large (max {mb} MB).', ['mb' => ProductJsonImporter::MAX_BYTES >> 20]));
        }
        $name = (string) $file->getClientFilename();
        if ($name !== '' && !preg_match('/\.(json|txt)$/i', $name)) {
            throw new RuntimeException($this->translator->trans('Upload a .json file (an export from this software).'));
        }

        $text = (string) $file->getStream();
        $products = $this->importer->parse($text); // throws with a readable message
        $options = $this->options($body);
        @set_time_limit(0);
        $report = $this->importer->run($products, $options, false);

        // Keep the file for the apply step.
        $this->removeStoredFile();
        $token = bin2hex(random_bytes(16));
        if (file_put_contents($this->dir() . '/' . $token . '.json', $text) === false) {
            throw new RuntimeException($this->translator->trans('Could not store the upload in var/tmp — check that the web server can write there.'));
        }
        $this->session->set(self::SESSION_KEY, ['token' => $token, 'filename' => mb_substr($name, 0, 120), 'options' => $options]);

        return $this->page($request, [], 200, $report);
    }

    /** @param array<string, mixed> $body */
    private function apply(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $pending = $this->pending();
        if ($pending === null || !hash_equals($pending['token'], (string) ($body['token'] ?? ''))) {
            throw new RuntimeException($this->translator->trans('That import has expired or was already applied. Upload the file again.'));
        }

        @set_time_limit(0); // downloading images can take a while
        $products = $this->importer->parse((string) file_get_contents($this->dir() . '/' . $pending['token'] . '.json'));
        $report = $this->importer->run($products, $pending['options'], true);
        $this->removeStoredFile();

        $c = $report['counts'];
        $parts = [
            $this->translator->transPlural('{count} product created', $c['create']),
            $this->translator->transPlural('{count} updated', $c['update']),
            $this->translator->transPlural('{count} unchanged', $c['unchanged']),
            $this->translator->transPlural('{count} image stored', $c['images_download'] + $c['images_embedded']),
        ];
        if ($c['images_failed'] > 0) {
            $parts[] = $this->translator->transPlural('{count} image could not be fetched', $c['images_failed']);
        }
        $message = $this->translator->trans('Import applied: {summary}', ['summary' => implode(', ', $parts)]);
        $message .= $c['error'] > 0 ? '; ' . $this->translator->transPlural('{count} product with errors was skipped.', $c['error']) : '.';
        $this->session->flash($c['error'] > 0 || $c['images_failed'] > 0 ? 'error' : 'success', $message);
        $this->logger->info('Product JSON import applied', ['counts' => $c, 'file' => $pending['filename']]);

        return $this->responder->redirectToRoute('admin.products');
    }

    private function cancel(): ResponseInterface
    {
        $this->removeStoredFile();

        return $this->responder->redirectToRoute('admin.products.import_json');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{create_categories: bool, update_existing: bool, images: bool}
     */
    private function options(array $body): array
    {
        return [
            'create_categories' => !empty($body['create_categories']),
            'update_existing'   => !empty($body['update_existing']),
            'images'            => !empty($body['images']),
        ];
    }

    /**
     * @param list<string>              $errors
     * @param array<string, mixed>|null $report
     */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200, ?array $report = null): ResponseInterface
    {
        $pending = $report !== null ? $this->pending() : null;
        if ($report !== null && count($report['rows']) > self::PREVIEW_ROWS) {
            // Errors first, so they are never cut off.
            usort($report['rows'], static fn (array $a, array $b): int => (($b['errors'] !== []) <=> ($a['errors'] !== [])) ?: $a['number'] <=> $b['number']);
            $report['hidden_rows'] = count($report['rows']) - self::PREVIEW_ROWS;
            $report['rows'] = array_slice($report['rows'], 0, self::PREVIEW_ROWS);
        }

        return $this->responder->view($request, 'admin/product-import-json.html.twig', [
            'errors'   => $errors,
            'report'   => $report,
            'pending'  => $pending,
            'statuses' => ProductRepository::STATUSES,
            'max_mb'   => ProductJsonImporter::MAX_BYTES >> 20,
            'max_products' => ProductJsonImporter::MAX_PRODUCTS,
        ], $status);
    }

    /** @return array{token: string, filename: string, options: array{create_categories: bool, update_existing: bool, images: bool}}|null */
    private function pending(): ?array
    {
        $pending = $this->session->get(self::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['token'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $pending['token']) || !is_array($pending['options'] ?? null)) {
            return null;
        }

        return is_file($this->dir() . '/' . $pending['token'] . '.json') ? $pending : null;
    }

    private function removeStoredFile(): void
    {
        $pending = $this->pending();
        if ($pending !== null) {
            @unlink($this->dir() . '/' . $pending['token'] . '.json');
        }
        $this->session->remove(self::SESSION_KEY);
    }

    /** var/tmp/imports, created on demand; uploads older than a day are cleaned up. */
    private function dir(): string
    {
        $dir = $this->paths->var('tmp/imports');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException($this->translator->trans('Could not create var/tmp/imports — check that the web server can write to var/.'));
        }
        foreach (glob($dir . '/*.json') ?: [] as $old) {
            if (filemtime($old) < time() - self::STALE_SECONDS) {
                @unlink($old);
            }
        }

        return $dir;
    }
}
