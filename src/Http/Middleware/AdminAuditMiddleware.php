<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RouteMatch;
use App\Http\Session;
use App\Security\AdminAuthenticator;
use App\Service\AuditLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Writes one activity-log entry for every successful state-changing request
 * to an ->admin() route (not ->withoutAudit()): the route, the form's
 * `action`, the entity it touched and the posted fields (passwords and form
 * tokens removed — see AuditLog::redact()). "Successful" means a status
 * below 400 and no new error flash message. Skipped when the controller
 * already wrote a richer entry with AuditLog::record().
 */
final class AdminAuditMiddleware implements MiddlewareInterface
{
    /** Route name => entity type; other routes use their name without "admin." / ".submit". */
    private const ENTITIES = [
        'admin.products.submit'   => 'product',
        'admin.categories.submit' => 'category',
        'admin.order.submit'      => 'order',
        'admin.shipping.submit'   => 'shipping_method',
        'admin.discounts.submit'  => 'coupon',
        'admin.settings.submit'   => 'settings',
        'admin.users.submit'      => 'admin_user',
        'admin.account.submit'    => 'admin_user',
    ];

    public function __construct(
        private readonly AuditLog $audit,
        private readonly AdminAuthenticator $auth,
        private readonly Session $session,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (
            !$match instanceof RouteMatch
            || !$match->route->requiresAdmin()
            || !$match->route->isAudited()
            || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            return $handler->handle($request);
        }

        $errorsBefore = $this->session->countFlashes('error');
        $response = $handler->handle($request);

        $user = $this->auth->user();
        if (
            $response->getStatusCode() >= 400
            || $user === null
            || $this->audit->hasRecorded()
            || $this->session->countFlashes('error') > $errorsBefore
        ) {
            return $response;
        }

        $this->recordGeneric($request, $match, $user);

        return $response;
    }

    /** @param array<string, mixed> $user */
    private function recordGeneric(ServerRequestInterface $request, RouteMatch $match, array $user): void
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $name = (string) $match->route->name;
        $formAction = is_string($body['action'] ?? null) ? $body['action'] : '';

        $entity = self::ENTITIES[$name] ?? (preg_replace('/^admin\.|\.submit$/', '', $name) ?: 'admin');
        $entityId = $match->params['id'] ?? $body['id'] ?? $body['product_id'] ?? null;
        if (str_contains($formAction, 'variant') && isset($body['variant_id'])) {
            $entity = 'variant';
            $entityId = $body['variant_id'];
        } elseif (str_contains($formAction, 'theme')) {
            $entity = 'theme';
            $entityId = $body['theme'] ?? null;
        }
        $entityId = is_scalar($entityId) && (string) $entityId !== '' ? (string) $entityId : null;

        $action = $entity . '.' . ($formAction !== '' ? $formAction : (string) (strrchr($name, '.') ?: '.post'));
        $action = str_replace('..', '.', $action);

        $details = $body;
        unset($details['action']);
        if (isset($match->params['id']) && !isset($details['id'])) {
            $details['id'] = $match->params['id'];
        }
        $files = $this->fileNames($request->getUploadedFiles());
        if ($files !== []) {
            $details['files'] = $files;
        }

        $summary = ucfirst(str_replace('_', ' ', $formAction !== '' ? $formAction : 'change')) . ' ' . str_replace('_', ' ', $entity)
            . ($entityId !== null ? ' #' . $entityId : '')
            . (is_array($body['ids'] ?? null) ? ' (' . count($body['ids']) . ' selected)' : '');

        $this->audit->record($request, $user, $action, $entity, $entityId, $summary, $details);
    }

    /**
     * @param array<array-key, mixed> $files
     * @return list<string> client file names of the uploads
     */
    private function fileNames(array $files): array
    {
        $names = [];
        array_walk_recursive($files, static function (mixed $file) use (&$names): void {
            if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
                $names[] = mb_substr((string) $file->getClientFilename(), 0, 120);
            }
        });

        return $names;
    }
}
