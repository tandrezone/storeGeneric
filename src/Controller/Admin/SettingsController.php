<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\Repository\SettingRepository;
use App\Service\StoreSettings;
use App\Support\Paths;
use App\Theme\Theme;
use App\Theme\ThemeManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/** Admin → Settings: store name, email and logo; choose, upload and delete themes. */
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

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'save_store':
                    $this->saveStore($body, $files['logo'] ?? null);
                    $this->session->flash('success', 'Store settings saved.');
                    break;

                case 'remove_logo':
                    $this->removeLogoFile();
                    $this->settings->set('logo_path', null);
                    $this->session->flash('success', 'Logo removed.');
                    break;

                case 'activate_theme':
                    $slug = (string) ($body['theme'] ?? '');
                    if (!$this->themes->exists($slug)) {
                        throw new RuntimeException('That theme is not installed.');
                    }
                    $this->settings->set('theme', $slug);
                    $this->session->flash('success', 'Theme changed.');
                    break;

                case 'upload_theme':
                    $this->uploadTheme($body, $files['theme_zip'] ?? null);
                    break;

                case 'delete_theme':
                    $slug = (string) ($body['theme'] ?? '');
                    if ($slug === $this->theme->name()) {
                        throw new RuntimeException('Switch to another theme before deleting the active one.');
                    }
                    $this->themes->delete($slug);
                    $this->session->flash('success', 'Theme deleted.');
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
            throw new RuntimeException('Store name is required (max 80 characters).');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid support email, or leave it empty.');
        }

        // Validate and store the logo first, so a rejected upload saves nothing.
        $newLogo = $logo instanceof UploadedFileInterface && $logo->getError() !== UPLOAD_ERR_NO_FILE
            ? $this->storeLogo($logo)
            : null;

        $this->settings->set('store_name', $name);
        $this->settings->set('store_email', $email !== '' ? $email : null);
        $this->settings->set('logo_show_name', !empty($body['logo_show_name']) ? '1' : '0');
        if ($newLogo !== null) {
            $this->removeLogoFile();
            $this->settings->set('logo_path', $newLogo);
        }
    }

    private function storeLogo(UploadedFileInterface $logo): string
    {
        if ($logo->getError() !== UPLOAD_ERR_OK || (int) $logo->getSize() > self::LOGO_MAX_BYTES) {
            throw new RuntimeException('Logo upload failed — use a PNG, JPG, WebP or GIF up to 2 MB.');
        }

        $dir = $this->paths->public(StoreSettings::LOGO_DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create public/' . StoreSettings::LOGO_DIR . ' — check folder permissions.');
        }

        $tmp = $dir . '/.upload-' . bin2hex(random_bytes(6));
        $logo->moveTo($tmp);
        $info = @getimagesize($tmp);
        $ext = is_array($info) ? (self::LOGO_TYPES[$info['mime']] ?? null) : null;
        if ($ext === null) {
            @unlink($tmp);
            throw new RuntimeException('The logo must be a PNG, JPG, WebP or GIF image.');
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

    /** @param array<string, mixed> $body */
    private function uploadTheme(array $body, mixed $zip): void
    {
        if (!$zip instanceof UploadedFileInterface || $zip->getError() !== UPLOAD_ERR_OK || (int) $zip->getSize() > ThemeManager::MAX_ZIP_BYTES) {
            throw new RuntimeException('Upload failed — choose a .zip file (max ' . (ThemeManager::MAX_ZIP_BYTES >> 20) . ' MB).');
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

        $message = "Theme \"{$result['name']}\" installed.";
        if ($result['skipped'] !== []) {
            $message .= ' Skipped ' . count($result['skipped']) . " file(s) that aren't allowed: "
                . implode(', ', array_slice($result['skipped'], 0, 6)) . (count($result['skipped']) > 6 ? '…' : '') . '.';
        }
        if (!empty($body['activate'])) {
            $this->settings->set('theme', $result['slug']);
            $message .= ' It is now the active theme.';
        }
        $this->session->flash('success', $message);
    }

    /** @param list<string> $errors */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->responder->view($request, 'admin/settings.html.twig', [
            'themes'              => $this->themes->all(),
            'active_theme'        => $this->theme->name(),
            'saved_email'         => $this->store->savedEmail(),
            'show_name_with_logo' => $this->store->showNameWithLogo(),
            'errors'              => $errors,
        ], $status);
    }
}
