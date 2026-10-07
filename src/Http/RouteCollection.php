<?php

declare(strict_types=1);

namespace App\Http;

/** Builder used by config/routes.php to declare the route table. */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];
    private string $prefix = '';
    /** @var list<callable(Route): void> */
    private array $groupModifiers = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $pattern, array $handler, ?string $name = null): Route
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler, $name);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $pattern, array $handler, ?string $name = null): Route
    {
        return $this->add(['POST'], $pattern, $handler, $name);
    }

    /**
     * @param list<string>                $methods
     * @param array{0: class-string, 1: string} $handler
     */
    public function add(array $methods, string $pattern, array $handler, ?string $name = null): Route
    {
        $route = new Route($methods, $this->prefix . $pattern, $handler, $name);
        foreach ($this->groupModifiers as $modify) {
            $modify($route);
        }
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Routes declared inside $define share the path prefix, and $modify is
     * applied to each of them (e.g. fn (Route $r) => $r->admin()).
     *
     * @param callable(self): void       $define
     * @param (callable(Route): mixed)|null $modify
     */
    public function group(string $prefix, callable $define, ?callable $modify = null): void
    {
        $previousPrefix = $this->prefix;
        $previousModifiers = $this->groupModifiers;

        $this->prefix .= $prefix;
        if ($modify !== null) {
            $this->groupModifiers[] = $modify;
        }

        $define($this);

        $this->prefix = $previousPrefix;
        $this->groupModifiers = $previousModifiers;
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }
}
