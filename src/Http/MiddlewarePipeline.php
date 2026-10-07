<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 request handler that runs the request through a list of
 * middleware (resolved from the container) and finally $handler.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $middleware,
        private readonly RequestHandlerInterface $handler,
        private readonly int $position = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->position])) {
            return $this->handler->handle($request);
        }

        /** @var MiddlewareInterface $current */
        $current = $this->container->get($this->middleware[$this->position]);
        $next = new self($this->container, $this->middleware, $this->handler, $this->position + 1);

        return $current->process($request, $next);
    }
}
