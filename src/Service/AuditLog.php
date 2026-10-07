<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ClientIp;
use App\Repository\AuditLogRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the admin activity log (Admin → Activity log). AdminAuditMiddleware
 * records every successful admin POST; controllers can call record() for a
 * richer entry, which then replaces the generic one for that request.
 *
 * Details are cleaned with redact(): anything that looks like a password,
 * token, secret or key is dropped, long text is shortened. A failure to
 * write the log is logged and never breaks the admin action itself.
 */
final class AuditLog
{
    /** Form fields never written to the log (matched as substrings, case-insensitive). */
    private const SECRET_FIELDS = ['password', 'passwd', 'csrf', 'token', 'secret', 'api_key', 'apikey', 'private_key', 'hash'];
    private const MAX_STRING = 200;
    private const MAX_ITEMS = 50;

    private bool $recorded = false;

    public function __construct(
        private readonly AuditLogRepository $log,
        private readonly ClientIp $clientIp,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $user    the acting admin (id, username), null for anonymous (failed login)
     * @param array<string, mixed>      $details extra context; cleaned with redact()
     */
    public function record(
        ServerRequestInterface $request,
        ?array $user,
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $summary = '',
        array $details = [],
        ?string $username = null,
    ): void {
        $this->recorded = true;
        $details = self::redact($details);

        try {
            $this->log->insert([
                'user_id'     => isset($user['id']) ? (int) $user['id'] : null,
                'username'    => mb_substr($username ?? (string) ($user['username'] ?? ''), 0, 190) ?: null,
                'action'      => mb_substr($action, 0, 80),
                'entity_type' => $entityType === null ? null : mb_substr($entityType, 0, 40),
                'entity_id'   => $entityId === null || $entityId === '' ? null : mb_substr((string) $entityId, 0, 64),
                'summary'     => mb_substr($summary, 0, 255),
                'details'     => $details === [] ? null : (string) json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'ip_address'  => substr($this->clientIp->of($request), 0, 45),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Could not write the admin activity log: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /** Whether record() was called during this request (the middleware then skips its generic entry). */
    public function hasRecorded(): bool
    {
        return $this->recorded;
    }

    /**
     * Drops secret-looking fields (recursively) and shortens long values.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if (++$count > self::MAX_ITEMS) {
                $clean['…'] = (count($data) - self::MAX_ITEMS) . ' more';
                break;
            }
            if (is_string($key) && self::isSecret($key)) {
                continue;
            }
            $clean[$key] = match (true) {
                is_array($value)                => self::redact($value),
                is_string($value)               => mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) . '…' : $value,
                is_scalar($value), $value === null => $value,
                default                         => get_debug_type($value),
            };
        }

        return $clean;
    }

    private static function isSecret(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SECRET_FIELDS as $secret) {
            if (str_contains($key, $secret)) {
                return true;
            }
        }

        return false;
    }
}
