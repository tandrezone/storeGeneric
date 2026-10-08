<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\UrlGuard;
use App\Support\Paths;
use Throwable;

/**
 * Downloads remote product images to local disk so the storefront never
 * hotlinks a third-party URL. UrlGuard resolves + validates the host
 * (SSRF guard), then curl is pinned to that exact IP.
 */
final class ImageDownloader
{
    public function __construct(
        private readonly Paths $paths,
        private readonly UrlGuard $urlGuard,
    ) {
    }

    private const PUBLIC_SUBDIR = 'assets/images/products';
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Downloads $url and returns its path relative to /public (e.g.
     * "assets/images/products/12-ab12cd34ef56.jpg"), or null if the URL is
     * empty/unreachable/not an allowed image type. Idempotent — re-running
     * with the same URL for the same product returns the cached file
     * instead of re-downloading it.
     */
    public function download(string $url, int $productId): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $filenameBase = $productId . '-' . substr(md5($url), 0, 12);
        $dir = $this->localDir();

        foreach (self::ALLOWED_MIME as $ext) {
            $existing = $dir . '/' . $filenameBase . '.' . $ext;
            if (is_file($existing)) {
                return self::PUBLIC_SUBDIR . '/' . basename($existing);
            }
        }

        try {
            $target = $this->urlGuard->resolvePublicHttpUrl($url);
        } catch (Throwable $e) {
            return null;
        }

        $data = $this->fetch($url, $target);
        if ($data === null) {
            return null;
        }

        return $this->storeBytes($data, $filenameBase);
    }

    /**
     * Stores image bytes (already in memory, e.g. embedded in an export) as
     * assets/images/products/<$name>.<ext> after checking they really are a
     * JPEG/PNG/WebP/GIF. Returns the path relative to /public, or null when
     * they aren't (or are too large). Idempotent for the same $name.
     */
    public function storeBytes(string $data, string $name): ?string
    {
        if ($data === '' || strlen($data) > self::MAX_BYTES || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return null;
        }

        $info = @getimagesizefromstring($data);
        $ext = self::ALLOWED_MIME[$info['mime'] ?? ''] ?? null;
        if ($ext === null) {
            return null;
        }

        $dir = $this->localDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $destination = $dir . '/' . $name . '.' . $ext;

        // Write to a temp file and rename into place — rename() is atomic,
        // so a concurrent request never sees a half-written file.
        $tmpPath = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmpPath, $data);
        rename($tmpPath, $destination);

        return self::PUBLIC_SUBDIR . '/' . basename($destination);
    }

    /**
     * Streams the response through a size-capped write callback so an
     * oversized or malicious response is aborted mid-transfer rather than
     * fully buffered into memory first.
     *
     * @param array{scheme: string, host: string, port: int, ip: string} $target
     */
    private function fetch(string $url, array $target): ?string
    {
        $buffer = '';
        $exceeded = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$exceeded) {
                $buffer .= $chunk;
                if (strlen($buffer) > self::MAX_BYTES) {
                    $exceeded = true;
                    return -1;
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($exceeded || $error !== '' || $httpCode >= 400 || $buffer === '') {
            return null;
        }

        return $buffer;
    }

    private function localDir(): string
    {
        return $this->paths->public(self::PUBLIC_SUBDIR);
    }
}
