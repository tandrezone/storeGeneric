<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Config;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The visitor's IP address. X-Forwarded-For is only believed when the
 * request comes from a proxy listed in TRUSTED_PROXIES (comma-separated
 * IPs or CIDR ranges, e.g. "127.0.0.1,10.0.0.0/8"); otherwise anyone could
 * pick their own address by sending the header.
 */
final class ClientIp
{
    public function __construct(private readonly Config $config)
    {
    }

    public function of(ServerRequestInterface $request): string
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        $trusted = $this->trustedProxies();
        if ($trusted === [] || !$this->isTrusted($remote, $trusted)) {
            return $remote;
        }

        // Walk the chain right to left: the first hop that isn't one of our
        // proxies is the client. Entries further left are client-supplied.
        $chain = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = $chain[$i];
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!$this->isTrusted($ip, $trusted)) {
                return $ip;
            }
        }

        return $remote;
    }

    /** @return list<string> */
    private function trustedProxies(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->config->get('TRUSTED_PROXIES')))));
    }

    /** @param list<string> $trusted */
    private function isTrusted(string $ip, array $trusted): bool
    {
        foreach ($trusted as $entry) {
            if ($this->inRange($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
