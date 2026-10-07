<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outermost middleware: turns exceptions into error pages (HTML) or JSON,
 * and logs unexpected ones. With APP_DEBUG=true the error details are shown.
 * Messages are shown in the request's language: plain English messages are
 * looked up in the catalog, so throw sites only translate messages with
 * placeholders themselves.
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Responder $responder,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
        private readonly bool $debug,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $response = $this->render($request, $e->status, $e->getMessage(), null);
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e, 'path' => $request->getUri()->getPath()]);

            return $this->render($request, 500, 'Something went wrong. Please try again in a moment.', $e);
        }
    }

    private function render(ServerRequestInterface $request, int $status, string $message, ?Throwable $e): ResponseInterface
    {
        $message = $this->translator->trans($message);
        $detail = $this->debug && $e !== null ? get_class($e) . ': ' . $e->getMessage() . "\n\n" . $e->getTraceAsString() : null;

        if ($this->wantsJson($request)) {
            return $this->responder->json(['success' => false, 'error' => $message] + ($detail ? ['debug' => $detail] : []), $status);
        }

        try {
            return $this->responder->view($request, 'error/error.html.twig', [
                'status' => $status,
                'message' => $message,
                'detail' => $detail,
            ], $status);
        } catch (Throwable $viewError) {
            // The error page itself failed (e.g. no database for the theme settings).
            $this->logger->critical('Error page failed to render', ['exception' => $viewError]);

            return $this->responder->text($message . ($detail ? "\n\n" . $detail : ''), $status);
        }
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        return str_starts_with($path, '/api/')
            || str_starts_with($path, '/admin/api/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}
