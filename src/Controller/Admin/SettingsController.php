<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\SettingRepository;
use App\Service\InvoiceService;
use App\Service\PromptLibrary;
use App\Service\StoreSettings;
use App\Service\TaxSettings;
use App\Support\Paths;
use App\Theme\Theme;
use App\Theme\ThemeManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/** Admin → Settings: store name, email, logo and default language; low-stock threshold; tax (VAT); AI prompt files; choose, upload and delete themes. */
final class SettingsController
{
    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly SettingRepository $settings,
        private readonly StoreSettings $store,
        private readonly ThemeManager $themes,
        private readonly Theme $theme,
        private readonly Paths $paths,
        private readonly TaxSettings $tax,
        private readonly Translator $translator,
        private readonly PromptLibrary $prompts,
        private readonly InvoiceService $invoices,
        private readonly \App\Service\ReturnService $returns,
        private readonly \App\Service\CartReminderService $cartReminders,
        private readonly \App\Service\MarketplaceOrderImporter $marketplace,
        private readonly \App\Service\OrderLinks $links,
        private readonly \App\Service\CurrencyService $currencies,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function blankTheme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->download($this->themes->blankThemeZip(), 'blank-theme.zip', 'application/zip', true);
    }

    /** Shows a stored prompt as plain text, or downloads it with ?download=1. */
    public function prompt(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $name = (string) ($query['file'] ?? '');
        $file = $this->prompts->path($name) ?? throw HttpException::notFound('Prompt not found.');

        if (!empty($query['download'])) {
            return $this->responder->download($file, $name, 'text/plain; charset=utf-8');
        }

        return $this->responder->text((string) file_get_contents($file));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'save_store':
                    $this->saveStore($body, $files['logo'] ?? null);
                    $this->session->flash('success', $this->translator->trans('Store settings saved.'));
                    break;

                case 'save_inventory':
                    $threshold = filter_var($body['low_stock_threshold'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
                    if ($threshold === false) {
                        throw new RuntimeException($this->translator->trans('The low-stock threshold must be a whole number of 0 or more.'));
                    }
                    $this->settings->set('low_stock_threshold', (string) $threshold);
                    $this->session->flash('success', $this->translator->trans('Inventory settings saved.'));
                    break;

                case 'marketplace_key':
                    if (($body['do'] ?? '') === 'revoke') {
                        $this->marketplace->revokeKey();
                        $this->session->flash('success', $this->translator->trans('Marketplace API key revoked. Imports are switched off.'));
                    } else {
                        // Shown once, on the page after this redirect.
                        $this->session->set('new_marketplace_key', $this->marketplace->generateKey());
                        $this->session->flash('success', $this->translator->trans('New marketplace API key created. Copy it now: it is only shown once.'));
                    }
                    break;

                case 'save_currencies':
                    $problems = $this->currencies->saveRates((string) ($body['currency_rates'] ?? ''), !empty($body['currency_rates_auto']));
                    if ($problems !== []) {
                        throw new RuntimeException($this->translator->trans('Could not read: {lines}. Use the form USD=1.10, GBP=0.85 (not your own currency).', ['lines' => implode(' | ', $problems)]));
                    }
                    if (($body['do'] ?? '') === 'update_now') {
                        $count = $this->currencies->updateFromEcb(new \App\Infrastructure\EcbRates());
                        $this->session->flash('success', $this->translator->transPlural('Updated {count} rate from the European Central Bank.', $count));
                    } else {
                        $this->session->flash('success', $this->translator->trans('Currency settings saved.'));
                    }
                    break;

                case 'save_carts':
                    $hours = filter_var($body['abandoned_cart_hours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 168]]);
                    if ($hours === false) {
                        throw new RuntimeException($this->translator->trans('The reminder delay must be a whole number of hours from 1 to 168.'));
                    }
                    $this->settings->set('abandoned_cart_enabled', !empty($body['abandoned_cart_enabled']) ? '1' : '0');
                    $this->settings->set('abandoned_cart_hours', (string) $hours);
                    $this->session->flash('success', $this->translator->trans('Cart reminder settings saved.'));
                    break;

                case 'save_returns':
                    $days = filter_var($body['return_window_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 365]]);
                    if ($days === false) {
                        throw new RuntimeException($this->translator->trans('The return window must be a whole number of days from 0 to 365.'));
                    }
                    $this->settings->set('return_window_days', (string) $days);
                    $this->session->flash('success', $this->translator->trans('Return settings saved.'));
                    break;

                case 'save_invoice':
                    $vat = mb_substr(trim((string) ($body['company_vat_id'] ?? '')), 0, 40);
                    $address = mb_substr(trim((string) ($body['company_address'] ?? '')), 0, 500);
                    $note = mb_substr(trim((string) ($body['invoice_note'] ?? '')), 0, 600);
                    $company = mb_substr(trim((string) ($body['company_name'] ?? '')), 0, 120);
                    $this->settings->set('company_name', $company !== '' ? $company : null);
                    $this->settings->set('company_address', $address !== '' ? $address : null);
                    $this->settings->set('company_vat_id', $vat !== '' ? $vat : null);
                    $country = trim((string) ($body['company_country'] ?? ''));
                    if ($country !== '' && \App\Support\EuCountries::code($country) === null) {
                        throw new RuntimeException($this->translator->trans('"Your country" must be an EU country (name or code such as PT).'));
                    }
                    $this->settings->set('company_country', $country !== '' ? (\App\Support\EuCountries::code($country) ?? null) : null);
                    $this->settings->set('invoice_attach', !empty($body['invoice_attach']) ? '1' : '0');
                    $this->settings->set('invoice_note', $note !== '' ? $note : null);
                    $this->session->flash('success', $this->translator->trans('Invoice details saved.'));
                    break;

                case 'save_privacy':
                    $snippet = trim((string) ($body['tracking_snippet'] ?? ''));
                    if (mb_strlen($snippet) > 10000) {
                        throw new RuntimeException($this->translator->trans('The tracking snippet is too long (max 10,000 characters).'));
                    }
                    $this->settings->set('cookie_notice', !empty($body['cookie_notice']) ? '1' : '0');
                    $this->settings->set('tracking_snippet', $snippet !== '' ? $snippet : null);
                    $this->session->flash('success', $this->translator->trans('Privacy settings saved.'));
                    break;

                case 'save_tax':
                    $this->tax->save($body);
                    $this->session->flash('success', $this->translator->trans('Tax settings saved.'));
                    break;

                case 'remove_logo':
                    $this->removeLogoFile();
                    $this->settings->set('logo_path', null);
                    $this->session->flash('success', $this->translator->trans('Logo removed.'));
                    break;

                case 'upload_prompts':
                    $this->uploadPrompts($files['prompts'] ?? []);
                    break;

                case 'delete_prompt':
                    $this->prompts->delete((string) ($body['prompt'] ?? ''));
                    $this->session->flash('success', $this->translator->trans('Prompt deleted.'));
                    break;

                case 'activate_theme':
                    $slug = (string) ($body['theme'] ?? '');
                    if (!$this->themes->exists($slug)) {
                        throw new RuntimeException($this->translator->trans('That theme is not installed.'));
                    }
                    $this->settings->set('theme', $slug);
                    $this->session->flash('success', $this->translator->trans('Theme changed.'));
                    break;

                case 'upload_theme':
                    $this->uploadTheme($body, $files['theme_zip'] ?? null);
                    break;

                case 'delete_theme':
                    $slug = (string) ($body['theme'] ?? '');
                    if ($slug === $this->theme->name()) {
                        throw new RuntimeException($this->translator->trans('Switch to another theme before deleting the active one.'));
                    }
                    $this->themes->delete($slug);
                    $this->session->flash('success', $this->translator->trans('Theme deleted.'));
                    break;
            }
        } catch (RuntimeException $e) {
            return $this->page($request, [$e->getMessage()], 422);
        }

        return $this->responder->redirectToRoute('admin.settings');
    }

    /** @param array<string, mixed> $body */
    private function saveStore(array $body, mixed $logo): void
    {
        $name = trim((string) ($body['store_name'] ?? ''));
        $email = trim((string) ($body['store_email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException($this->translator->trans('Store name is required (max 80 characters).'));
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException($this->translator->trans('Please enter a valid support email, or leave it empty.'));
        }
        // Not posted (older form): keep the current language.
        $language = isset($body['store_language']) ? $this->translator->normalize((string) $body['store_language'])
            : ($this->translator->normalize($this->store->language()) ?? Translator::DEFAULT_LOCALE);
        if ($language === null) {
            throw new RuntimeException($this->translator->trans('Choose one of the available store languages.'));
        }

        // Validate and store the logo first, so a rejected upload saves nothing.
        $newLogo = $logo instanceof UploadedFileInterface && $logo->getError() !== UPLOAD_ERR_NO_FILE
            ? $this->storeLogo($logo)
            : null;

        $this->settings->set('store_name', $name);
        $this->settings->set('store_email', $email !== '' ? $email : null);
        $this->settings->set('logo_show_name', !empty($body['logo_show_name']) ? '1' : '0');
        $this->settings->set('store_language', $language);
        if ($newLogo !== null) {
            $this->removeLogoFile();
            $this->settings->set('logo_path', $newLogo);
        }
    }

    private function storeLogo(UploadedFileInterface $logo): string
    {
        if ($logo->getError() !== UPLOAD_ERR_OK || (int) $logo->getSize() > self::LOGO_MAX_BYTES) {
            throw new RuntimeException($this->translator->trans('Logo upload failed — use a PNG, JPG, WebP or GIF up to 2 MB.'));
        }

        $dir = $this->paths->public(StoreSettings::LOGO_DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException($this->translator->trans('Could not create public/{dir} — check folder permissions.', ['dir' => StoreSettings::LOGO_DIR]));
        }

        $tmp = $dir . '/.upload-' . bin2hex(random_bytes(6));
        $logo->moveTo($tmp);
        $info = @getimagesize($tmp);
        $ext = is_array($info) ? (self::LOGO_TYPES[$info['mime']] ?? null) : null;
        if ($ext === null) {
            @unlink($tmp);
            throw new RuntimeException($this->translator->trans('The logo must be a PNG, JPG, WebP or GIF image.'));
        }

        $relative = StoreSettings::LOGO_DIR . '/logo-' . bin2hex(random_bytes(4)) . '.' . $ext;
        rename($tmp, $this->paths->public($relative));

        return $relative;
    }

    private function removeLogoFile(): void
    {
        $current = $this->settings->get('logo_path');
        if ($current !== null && str_starts_with($current, StoreSettings::LOGO_DIR . '/') && !str_contains($current, '..')) {
            @unlink($this->paths->public($current));
        }
    }

    /** Stores every valid file; reports the rejected ones without losing the rest. */
    private function uploadPrompts(mixed $uploads): void
    {
        $uploads = array_filter(
            is_array($uploads) ? $uploads : [$uploads],
            static fn ($f) => $f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE
        );
        if ($uploads === []) {
            throw new RuntimeException($this->translator->trans('Choose one or more .md or .txt files to upload.'));
        }

        $saved = 0;
        $errors = [];
        foreach ($uploads as $upload) {
            try {
                $this->prompts->store($upload);
                $saved++;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($saved === 0) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $message = $this->translator->transPlural('{count} prompt uploaded.', $saved);
        if ($errors !== []) {
            $message .= ' ' . implode(' ', $errors);
        }
        $this->session->flash('success', $message);
    }

    /** @param array<string, mixed> $body */
    private function uploadTheme(array $body, mixed $zip): void
    {
        if (!$zip instanceof UploadedFileInterface || $zip->getError() !== UPLOAD_ERR_OK || (int) $zip->getSize() > ThemeManager::MAX_ZIP_BYTES) {
            throw new RuntimeException($this->translator->trans('Upload failed — choose a .zip file (max {mb} MB).', ['mb' => ThemeManager::MAX_ZIP_BYTES >> 20]));
        }

        $tmp = $this->paths->var('tmp');
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $file = $tmp . '/theme-' . bin2hex(random_bytes(6)) . '.zip';
        $zip->moveTo($file);

        try {
            $result = $this->themes->install($file, !empty($body['replace']));
        } finally {
            @unlink($file);
        }

        $message = $this->translator->trans('Theme "{name}" installed.', ['name' => $result['name']]);
        if ($result['skipped'] !== []) {
            $message .= ' ' . $this->translator->transPlural('Skipped {count} file that isn\'t allowed: {files}.', count($result['skipped']), [
                'files' => implode(', ', array_slice($result['skipped'], 0, 6)) . (count($result['skipped']) > 6 ? '…' : ''),
            ]);
        }
        if (!empty($body['activate'])) {
            $this->settings->set('theme', $result['slug']);
            $message .= ' ' . $this->translator->trans('It is now the active theme.');
        }
        $this->session->flash('success', $message);
    }

    /** @param list<string> $errors */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200): ResponseInterface
    {
        $newKey = $this->session->get('new_marketplace_key');
        $this->session->remove('new_marketplace_key'); // a key is shown once
        return $this->responder->view($request, 'admin/settings.html.twig', [
            'themes'              => $this->themes->all(),
            'active_theme'        => $this->theme->name(),
            'saved_email'         => $this->store->savedEmail(),
            'show_name_with_logo' => $this->store->showNameWithLogo(),
            'low_stock_threshold' => $this->store->lowStockThreshold(),
            'tax'                 => $this->tax->toArray(),
            'marketplace'         => ['has_key' => $this->marketplace->hasKey(), 'new_key' => $newKey, 'endpoint' => $this->links->baseUrl() . '/api/marketplace/orders'],
            'currencies'          => ['base' => $this->currencies->base(), 'rates' => $this->currencies->ratesText(), 'auto' => $this->currencies->autoUpdate(), 'date' => $this->currencies->updatedOn()],
            'cart_reminders'      => ['enabled' => $this->cartReminders->enabled(), 'hours' => $this->cartReminders->afterHours()],
            'return_window_days'  => $this->returns->windowDays(),
            'seller'              => $this->invoices->seller() + ['attach' => $this->invoices->attachEnabled(), 'country' => (string) $this->settings->get('company_country', '')],
            'privacy'             => ['notice' => $this->store->cookieNoticeEnabled(), 'snippet' => $this->store->trackingSnippet()],
            'prompts'             => $this->prompts->all(),
            'prompt_max_kb'       => PromptLibrary::MAX_BYTES >> 10,
            'store_language'      => $this->translator->normalize($this->store->language()) ?? 'en',
            'errors'              => $errors,
        ], $status);
    }
}
