<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Service\Thumbnails;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Makes a missing thumbnail (see Thumbnails) and sends it. Once the file
 * exists the web server serves it directly and this is never reached.
 */
final class ThumbnailController
{
    public function __construct(
        private readonly Thumbnails $thumbnails,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function show(ServerRequestInterface $request, int $width, string $path): ResponseInterface
    {
        $file = $this->thumbnails->generate($width, rawurldecode($path))
            ?? throw HttpException::notFound('Image not found.');

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg')
            ->withHeader('Cache-Control', 'public, max-age=31536000')
            ->withBody($this->streams->createStreamFromFile($file));
    }
}
