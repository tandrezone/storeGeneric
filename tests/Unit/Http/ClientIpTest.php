<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\ClientIp;
use App\Support\Config;
use Nyholm\Psr7\ServerRequest;
use Tests\TestCase;

final class ClientIpTest extends TestCase
{
    public function testUsesRemoteAddressWithoutTrustedProxies(): void
    {
        $ip = new ClientIp(Config::fromArray([]));
        $this->assertSame('203.0.113.9', $ip->of($this->request('203.0.113.9', '1.2.3.4')));
        $this->assertSame('unknown', $ip->of($this->request('not-an-ip')));
        $this->assertSame('unknown', $ip->of($this->request('')));
    }

    public function testForwardedForOnlyFromTrustedProxy(): void
    {
        $ip = new ClientIp(Config::fromArray(['TRUSTED_PROXIES' => '10.0.0.0/8, 127.0.0.1']));
        $this->assertSame('198.51.100.7', $ip->of($this->request('10.1.2.3', '198.51.100.7')));
        $this->assertSame('198.51.100.7', $ip->of($this->request('127.0.0.1', '198.51.100.7, 10.0.0.5')), 'skips our own proxies, right to left');
        $this->assertSame('198.51.100.7', $ip->of($this->request('127.0.0.1', '6.6.6.6, 198.51.100.7')), 'client-supplied entries further left are ignored');
        $this->assertSame('203.0.113.1', $ip->of($this->request('203.0.113.1', '6.6.6.6')), 'untrusted remote: header ignored');
        $this->assertSame('10.1.2.3', $ip->of($this->request('10.1.2.3', 'garbage')), 'invalid header: the proxy address');
    }

    public function testIpv6Ranges(): void
    {
        $ip = new ClientIp(Config::fromArray(['TRUSTED_PROXIES' => '2001:db8::/32']));
        $this->assertSame('2001:4860::1', $ip->of($this->request('2001:db8::5', '2001:4860::1')));
        $this->assertSame('2001:db9::5', $ip->of($this->request('2001:db9::5', '2001:4860::1')));
    }

    private function request(string $remote, ?string $forwardedFor = null): ServerRequest
    {
        $request = new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => $remote]);

        return $forwardedFor !== null ? $request->withHeader('X-Forwarded-For', $forwardedFor) : $request;
    }
}
