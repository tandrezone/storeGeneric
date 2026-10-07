<?php

declare(strict_types=1);

namespace App;

use App\Http\ControllerDispatcher;
use App\Http\MiddlewarePipeline;
use App\Http\ResponseEmitter;
use App\Support\Config;
use App\Support\Paths;
use DI\Container;
use DI\ContainerBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application entry point: builds the container from config/, then runs
 * each request through the middleware pipeline to a controller.
 */
final class Kernel
{
    private function __construct(private readonly Container $container)
    {
    }

    public static function boot(string $projectRoot): self
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            Paths::class  => new Paths($projectRoot),
            Config::class => Config::fromEnvironment($projectRoot),
        ]);
        $builder->addDefinitions($projectRoot . '/config/container.php');

        return new self($builder->build());
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $paths = $this->container->get(Paths::class);
        $middleware = require $paths->root . '/config/middleware.php';

        $pipeline = new MiddlewarePipeline(
            $this->container,
            $middleware,
            $this->container->get(ControllerDispatcher::class)
        );

        return $pipeline->handle($request);
    }

    /** Handles the current PHP request and sends the response. */
    public function run(): void
    {
        $factory = $this->container->get(Psr17Factory::class);
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

        $this->container->get(ResponseEmitter::class)->emit($this->handle($request));
    }
}
