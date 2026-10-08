<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Turns a CSV written with App\Support\Csv (or another text export, such as
 * the JSON product export) into a download response. The
 * stream is sent in chunks by ResponseEmitter (php://temp spills to disk
 * past 2 MB), so large exports never sit in memory as one string.
 */
final class CsvDownload
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** @param resource $handle */
    public function response($handle, string $filename, string $contentType = 'text/csv; charset=utf-8'): ResponseInterface
    {
        rewind($handle);
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streams->createStreamFromResource($handle));
    }
}
