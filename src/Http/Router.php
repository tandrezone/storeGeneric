<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Exception\HttpException;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use InvalidArgumentException;

use function FastRoute\simpleDispatcher;

/** Matches requests against the route table (FastRoute) and builds URLs from route names. */
final class Router
{
    private Dispatcher $dispatcher;
    /** @var array<string, Route> */
    private array $named = [];

    public function __construct(RouteCollection $routes)
    {
        foreach ($routes->all() as $route) {
            if ($route->name !== null) {
                $this->named[$route->name] = $route;
            }
        }

        $this->dispatcher = simpleDispatcher(function (RouteCollector $collector) use ($routes): void {
            foreach ($routes->all() as $route) {
                $collector->addRoute($route->methods, $route->pattern, $route);
            }
        });
    }

    /** @throws HttpException 404 or 405 */
    public function match(string $method, string $path): RouteMatch
    {
        $result = $this->dispatcher->dispatch($method, $path);

        return match ($result[0]) {
            Dispatcher::FOUND => new RouteMatch($result[1], $result[2]),
            Dispatcher::METHOD_NOT_ALLOWED => throw new HttpException(405, 'Method not allowed.', ['Allow' => implode(', ', $result[1])]),
            default => throw HttpException::notFound(),
        };
    }

    /**
     * Path for a named route, e.g. url('product.show', ['id' => 12]) → /product/12.
     *
     * @param array<string, scalar> $params path placeholders
     * @param array<string, scalar|null> $query  appended as a query string
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Unknown route \"{$name}\".");

        $path = preg_replace_callback(
            '/\{(\w+)(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/',
            static function (array $m) use ($params, $name): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new InvalidArgumentException("Missing \"{$m[1]}\" for route \"{$name}\".");
                }

                return rawurlencode((string) $params[$m[1]]);
            },
            $route->pattern
        );

        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');

        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }
}
