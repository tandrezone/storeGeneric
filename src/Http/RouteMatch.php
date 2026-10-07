<?php

declare(strict_types=1);

namespace App\Http;

/** The route that matched the current request, plus its path parameters. */
final class RouteMatch
{
    public const ATTRIBUTE = '_route';

    /** @param array<string, string> $params */
    public function __construct(
        public readonly Route $route,
        public readonly array $params,
    ) {
    }
}
