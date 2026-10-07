<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Exception\HttpException;
use App\Http\RouteCollection;
use App\Http\Router;
use InvalidArgumentException;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    public function setUp(): void
    {
        $routes = new RouteCollection();
        $routes->get('/', ['Home', 'index'], 'home');
        $routes->get('/product/{id:\d+}[-{slug:[^/]+}]', ['Product', 'show'], 'product.show');
        $routes->get('/thumbs/{width:\d{3}}/{path:.+}', ['Thumb', 'show'], 'thumb');
        $routes->group('/admin', static function (RouteCollection $r): void {
            $r->get('/orders/{id:\d+}', ['Order', 'show'], 'admin.order');
            $r->post('/orders/{id:\d+}', ['Order', 'handle'], 'admin.order.submit');
        }, static fn ($route) => $route->admin());
        $this->router = new Router($routes);
    }

    public function testOptionalSegmentIsIncludedOnlyWithItsValue(): void
    {
        $this->assertSame('/product/12-green-tea', $this->router->url('product.show', ['id' => 12, 'slug' => 'green-tea']));
        $this->assertSame('/product/12', $this->router->url('product.show', ['id' => 12]));
        $this->assertSame('/product/12', $this->router->url('product.show', ['id' => 12, 'slug' => '']));
    }

    public function testRegexWithBracesAndEncoding(): void
    {
        $this->assertSame('/thumbs/400/a%20b', $this->router->url('thumb', ['width' => 400, 'path' => 'a b']));
    }

    public function testQueryStringDropsEmptyValues(): void
    {
        $this->assertSame('/?q=tea&page=2', $this->router->url('home', [], ['q' => 'tea', 'sort' => null, 'cat' => '', 'page' => 2]));
        $this->assertSame('/', $this->router->url('home', [], ['q' => null]));
    }

    public function testErrors(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->router->url('nope'), 'Unknown route');
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->router->url('product.show'), 'Missing "id"');
    }

    public function testMatching(): void
    {
        $match = $this->router->match('GET', '/product/7-tea');
        $this->assertSame(['id' => '7', 'slug' => 'tea'], $match->params);
        $this->assertTrue($this->router->match('GET', '/admin/orders/3')->route->requiresAdmin());

        $e = $this->assertThrows(HttpException::class, fn () => $this->router->match('DELETE', '/admin/orders/3'));
        $this->assertSame(405, $e instanceof HttpException ? $e->status : 0);
        $this->assertSame('GET, HEAD, POST', $e instanceof HttpException ? $e->headers['Allow'] : '');
        $e = $this->assertThrows(HttpException::class, fn () => $this->router->match('GET', '/missing'));
        $this->assertSame(404, $e instanceof HttpException ? $e->status : 0);
    }
}
