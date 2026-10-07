<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

/**
 * Blocks server-side requests to internal/private network destinations
 * (SSRF guard). Resolves the hostname once so the caller can pin curl to
 * that IP, closing the gap where DNS could point somewhere else between
 * the check and the actual fetch.
 */
final class UrlGuard
{
    /**
     * Validates $url and returns ['scheme' => , 'host' => , 'port' => , 'ip' => ].
     * Throws InvalidArgumentException if the URL is missing, malformed, uses
     * a non-HTTP(S) scheme, or resolves to a private/reserved/loopback address.
     *
     * @return array{scheme: string, host: string, port: int, ip: string}
     */
    public function resolvePublicHttpUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new InvalidArgumentException('That URL could not be parsed.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http:// and https:// URLs are allowed.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : $this->resolveHostname($host);

        if ($ip === null) {
            throw new InvalidArgumentException('Could not resolve that host.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('That URL points to a private or reserved network address, which is not allowed.');
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => (int) $port, 'ip' => $ip];
    }

    private function resolveHostname(string $host): ?string
    {
        $records = dns_get_record($host, DNS_A + DNS_AAAA);
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                return $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                return $record['ipv6'];
            }
        }

        $ipv4 = gethostbyname($host);
        return $ipv4 !== $host ? $ipv4 : null;
    }
}
