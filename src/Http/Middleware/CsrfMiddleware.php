<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exception\HttpException;
use App\Http\RouteMatch;
use App\Http\Session;
use App\I18n\LocaleFormat;
use App\I18n\Translator;
use App\Security\Csrf;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state-changing requests (POST/PUT/PATCH/DELETE) without a valid
 * token in the `csrf_token` field or X-CSRF-Token header. Routes marked
 * ->withoutCsrf() (webhooks) are skipped.
 *
 * A form bigger than post_max_size reaches PHP with an empty body (so no
 * token either); that case gets an "upload too large" message instead of
 * "session expired".
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly ResponseFactoryInterface $responses,
        private readonly Translator $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        $unsafe = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($unsafe && $match instanceof RouteMatch && $match->route->requiresCsrf()) {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ($body[Csrf::FIELD] ?? null) : null;
            $token ??= $request->getHeaderLine(Csrf::HEADER) ?: null;

            if (!$this->csrf->isValid(is_string($token) ? $token : null)) {
                if ($this->bodyWasDiscarded($request)) {
                    return $this->tooLarge($request);
                }
                // Static text: ErrorHandlerMiddleware translates it.
                throw HttpException::badRequest('Your session expired or the form was already used. Please go back, reload the page and try again.');
            }
        }

        return $handler->handle($request);
    }

    /** PHP drops the whole form body (fields and files) when it exceeds post_max_size. */
    private function bodyWasDiscarded(ServerRequestInterface $request): bool
    {
        $length = (int) ($request->getServerParams()['CONTENT_LENGTH'] ?? $request->getHeaderLine('Content-Length'));
        $body = $request->getParsedBody();
        $max = $this->postMaxBytes();

        return $max > 0
            && $length > $max
            && (!is_array($body) || $body === [])
            && $request->getUploadedFiles() === [];
    }

    /** Back to the form (same site only) with a message, or a plain 413 page. */
    private function tooLarge(ServerRequestInterface $request): ResponseInterface
    {
        $message = $this->translator->trans(
            'The upload is too large (limit {limit}). Choose a smaller file, or fewer files at once.',
            ['limit' => $this->formatBytes($this->postMaxBytes())]
        );

        $referer = parse_url($request->getHeaderLine('Referer')) ?: [];
        $sameHost = isset($referer['host']) && strcasecmp($referer['host'], $request->getUri()->getHost()) === 0;
        $path = (string) ($referer['path'] ?? '');
        if (!$sameHost || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new HttpException(413, $message);
        }

        $this->session->flash('error', $message);
        $location = $path . (isset($referer['query']) ? '?' . $referer['query'] : '');

        return $this->responses->createResponse(303)->withHeader('Location', $location);
    }

    private function postMaxBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G'     => $number << 30,
            'M'     => $number << 20,
            'K'     => $number << 10,
            default => $number,
        };
    }

    private function formatBytes(int $bytes): string
    {
        $locale = $this->translator->intlLocale();

        return match (true) {
            $bytes <= 0       => $this->translator->trans('unlimited'),
            $bytes >= 1 << 20 => LocaleFormat::number(round($bytes / (1 << 20), 1), $locale, fmod(round($bytes / (1 << 20), 1), 1.0) == 0.0 ? 0 : 1) . ' MB',
            $bytes >= 1 << 10 => LocaleFormat::number(round($bytes / (1 << 10)), $locale) . ' KB',
            default           => $this->translator->transPlural('{count} byte', $bytes),
        };
    }
}
