<?php

declare(strict_types=1);

namespace App\Http;

use App\View\View;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Builds PSR-7 responses for controllers: views, JSON, redirects, downloads. */
final class Responder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly View $view,
        private readonly Router $router,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function view(ServerRequestInterface $request, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $html = $this->view->render($template, $data + ['current_path' => $request->getUri()->getPath()]);

        return $this->html($html, $status);
    }

    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streams->createStream($html));
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($this->streams->createStream($text));
    }

    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($data, JSON_UNESCAPED_SLASHES)));
    }

    public function redirect(string $url, int $status = 302): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Location', $url);
    }

    /**
     * @param array<string, scalar> $params
     * @param array<string, scalar|null> $query
     */
    public function redirectToRoute(string $name, array $params = [], array $query = [], int $status = 302): ResponseInterface
    {
        return $this->redirect($this->router->url($name, $params, $query), $status);
    }

    /** Streams a file as a download, deleting it afterwards when $deleteAfter is true. */
    public function download(string $file, string $filename, string $contentType, bool $deleteAfter = false): ResponseInterface
    {
        $contents = (string) file_get_contents($file);
        if ($deleteAfter) {
            @unlink($file);
        }

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($contents));
    }
}
