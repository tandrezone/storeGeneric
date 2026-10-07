<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\AdminSecurityHeadersMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testAdminGetsStrictHeaders(): void
    {
        $response = $this->process('/admin/orders', new Response());
        $this->assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testStorefrontGetsReferrerPolicyButNoCsp(): void
    {
        $response = $this->process('/order/confirmation/ORD-1', new Response());
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
        $this->assertFalse($response->hasHeader('X-Frame-Options'));

        $own = $this->process('/administrator', (new Response())->withHeader('Referrer-Policy', 'no-referrer'));
        $this->assertSame('no-referrer', $own->getHeaderLine('Referrer-Policy'), 'a controller\'s own header wins');
    }

    private function process(string $path, ResponseInterface $response): ResponseInterface
    {
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return (new AdminSecurityHeadersMiddleware())->process(new ServerRequest('GET', $path), $handler);
    }
}
