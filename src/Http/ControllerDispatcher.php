<?php

declare(strict_types=1);

namespace App\Http;

use DI\Container;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Last stop of the pipeline: calls the matched controller method.
 * Arguments are injected by name — `$request` gets the PSR-7 request,
 * route placeholders like {id} map to parameters of the same name
 * (digits become ints), and anything else is resolved from the container.
 */
final class ControllerDispatcher implements RequestHandlerInterface
{
    public function __construct(private readonly Container $container)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (!$match instanceof RouteMatch) {
            throw new LogicException('No route was matched before dispatching.');
        }

        [$class, $method] = $match->route->handler;
        $params = array_map(
            static fn (string $v): int|string => ctype_digit($v) && strlen($v) < 19 ? (int) $v : $v,
            $match->params
        );

        $response = $this->container->call(
            [$this->container->get($class), $method],
            ['request' => $request] + $params
        );

        if (!$response instanceof ResponseInterface) {
            throw new LogicException("{$class}::{$method}() must return a ResponseInterface.");
        }

        return $response;
    }
}
