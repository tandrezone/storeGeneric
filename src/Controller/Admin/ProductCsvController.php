<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\CsvDownload;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ProductRepository;
use App\Service\CsvExport;
use App\Service\ProductCsvImporter;
use App\Service\StoreSettings;
use App\Support\Paths;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Admin → Products → Export CSV / Import CSV.
 *
 * Import is two steps: the upload is checked and shown as a preview (what
 * each row would create or change, and its errors) without writing
 * anything; "Apply" then re-checks the same file against the current data
 * and writes it in one transaction. Between the steps the file waits in
 * var/tmp/imports/ under a random name remembered in the session.
 */
final class ProductCsvController
{
    private const SESSION_KEY = 'product_import';
    private const STALE_SECONDS = 86400;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CsvDownload $download,
        private readonly CsvExport $export,
        private readonly ProductCsvImporter $importer,
        private readonly StoreSettings $store,
        private readonly Paths $paths,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
    ) {
    }

    /** Same filters as the product list: ?status=…&stock=low */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = (string) ($query['status'] ?? '');
        $status = in_array($status, ProductRepository::STATUSES, true) ? $status : null;
        $lowStock = ($query['stock'] ?? '') === 'low' ? $this->store->lowStockThreshold() : null;

        return $this->download->response(
            $this->export->products($status, $lowStock),
            'products-' . date('Ymd-His') . '.csv'
        );
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
        } catch (PDOException $e) { // (a RuntimeException too, but not a message for the admin)
            $this->logger->warning('Product CSV import failed', ['exception' => $e]);

            return $this->page($request, [$this->translator->trans('A database error occurred — nothing was changed. Please try again.')], 500);
        } catch (RuntimeException $e) {
            return $this->page($request, [$e->getMessage()], 422);
        }
    }

    /** @param array<string, mixed> $body */
    private function preview(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $file = $request->getUploadedFiles()['csv'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException($this->translator->trans('Choose a CSV file to import.'));
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->translator->trans(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The upload failed — the file is too large.' : 'The upload failed.'));
        }
        if ((int) $file->getSize() > ProductCsvImporter::MAX_BYTES) {
            throw new RuntimeException($this->translator->trans('The file is too large (max {mb} MB).', ['mb' => ProductCsvImporter::MAX_BYTES >> 20]));
        }
        $name = (string) $file->getClientFilename();
        if ($name !== '' && !preg_match('/\.(csv|txt)$/i', $name)) {
            throw new RuntimeException($this->translator->trans('Upload a .csv file (save the spreadsheet as "CSV UTF-8").'));
        }

        $text = (string) $file->getStream();
        $rows = $this->importer->readRows($text); // throws with a readable message
        $createCategories = !empty($body['create_categories']);
        $report = $this->importer->run($rows, $createCategories, false);

        // Keep the file for the apply step.
        $this->removeStoredFile();
        $dir = $this->dir();
        $token = bin2hex(random_bytes(16));
        if (file_put_contents($dir . '/' . $token . '.csv', $text) === false) {
            throw new RuntimeException($this->translator->trans('Could not store the upload in var/tmp — check that the web server can write there.'));
        }
        $this->session->set(self::SESSION_KEY, [
            'token'             => $token,
            'filename'          => mb_substr($name, 0, 120),
            'create_categories' => $createCategories,
        ]);

        return $this->page($request, [], 200, $report);
    }

    /** @param array<string, mixed> $body */
    private function apply(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $pending = $this->pending();
        if ($pending === null || !hash_equals($pending['token'], (string) ($body['token'] ?? ''))) {
            throw new RuntimeException($this->translator->trans('That import has expired or was already applied. Upload the file again.'));
        }

        $text = (string) file_get_contents($this->dir() . '/' . $pending['token'] . '.csv');
        $rows = $this->importer->readRows($text);
        try {
            $report = $this->importer->run($rows, (bool) $pending['create_categories'], true);
        } catch (PDOException $e) {
            $this->logger->warning('Product CSV import failed', ['exception' => $e]);
            throw new RuntimeException($this->translator->trans('The import was rolled back because of a database error (nothing was changed). Check the file and try again.'));
        }
        $this->removeStoredFile();

        $c = $report['counts'];
        $parts = [
            $this->translator->transPlural('{count} product created', $c['create_product']),
            $this->translator->transPlural('{count} variant added', $c['create_variant']),
            $this->translator->transPlural('{count} updated', $c['update']),
            $this->translator->transPlural('{count} unchanged', $c['unchanged']),
        ];
        if ($c['new_categories'] > 0) {
            $parts[] = $this->translator->transPlural('{count} new category', $c['new_categories']);
        }
        $message = $this->translator->trans('Import applied: {summary}', ['summary' => implode(', ', $parts)]);
        $message .= $c['error'] > 0 ? '; ' . $this->translator->transPlural('{count} row with errors was skipped.', $c['error']) : '.';
        $this->session->flash($c['error'] > 0 ? 'error' : 'success', $message);
        $this->logger->info('Product CSV import applied', ['counts' => $c, 'file' => $pending['filename']]);

        return $this->responder->redirectToRoute('admin.products');
    }

    private function cancel(): ResponseInterface
    {
        $this->removeStoredFile();

        return $this->responder->redirectToRoute('admin.products.import');
    }

    /**
     * @param list<string>              $errors
     * @param array<string, mixed>|null $report
     */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200, ?array $report = null): ResponseInterface
    {
        $pending = $report !== null ? $this->pending() : null;

        return $this->responder->view($request, 'admin/product-import.html.twig', [
            'errors'    => $errors,
            'report'    => $report,
            'pending'   => $pending,
            'columns'   => ProductCsvImporter::COLUMNS,
            'statuses'  => ProductRepository::STATUSES,
            'max_mb'    => ProductCsvImporter::MAX_BYTES >> 20,
            'max_rows'  => ProductCsvImporter::MAX_ROWS,
        ], $status);
    }

    /** @return array{token: string, filename: string, create_categories: bool}|null */
    private function pending(): ?array
    {
        $pending = $this->session->get(self::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['token'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $pending['token'])) {
            return null;
        }

        return is_file($this->dir() . '/' . $pending['token'] . '.csv') ? $pending : null;
    }

    private function removeStoredFile(): void
    {
        $pending = $this->pending();
        if ($pending !== null) {
            @unlink($this->dir() . '/' . $pending['token'] . '.csv');
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
        foreach (glob($dir . '/*.csv') ?: [] as $old) {
            if (filemtime($old) < time() - self::STALE_SECONDS) {
                @unlink($old);
            }
        }

        return $dir;
    }
}
