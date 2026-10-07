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
     * Path for a named route, e.g. url('product.show', ['id' => 12, 'slug' => 'tea']) → /product/12-tea.
     * Optional trailing parts ("[-{slug}]") are left out when their placeholders aren't given.
     *
     * @param array<string, scalar> $params path placeholders
     * @param array<string, scalar|null> $query  appended as a query string
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Unknown route \"{$name}\".");

        $path = '';
        foreach ($this->segments($route->pattern) as $i => $segment) {
            preg_match_all('/\{(\w+)/', $segment, $names);
            $given = array_filter($names[1], static fn (string $p) => ($params[$p] ?? '') !== '');
            if ($i > 0 && count($given) !== count($names[1])) {
                break; // optional part without its values
            }
            $path .= preg_replace_callback(
                '/\{(\w+)(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/',
                static function (array $m) use ($params, $name): string {
                    if (!array_key_exists($m[1], $params)) {
                        throw new InvalidArgumentException("Missing \"{$m[1]}\" for route \"{$name}\".");
                    }

                    return rawurlencode((string) $params[$m[1]]);
                },
                $segment
            );
        }

        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');

        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    /**
     * Splits a FastRoute pattern into its required part and optional parts:
     * "/a/{id}[-{slug}]" → ["/a/{id}", "-{slug}"]. Brackets inside {…} regexes are kept.
     *
     * @return list<string>
     */
    private function segments(string $pattern): array
    {
        $segments = [''];
        $depth = 0;
        foreach (str_split($pattern) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            } elseif ($depth === 0 && $char === '[') {
                $segments[] = '';
                continue;
            } elseif ($depth === 0 && $char === ']') {
                continue;
            }
            $segments[count($segments) - 1] .= $char;
        }

        return $segments;
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }
}
