<?php

/**
 * PSR-11 container definitions (PHP-DI). Classes not listed here are
 * autowired from their constructor type hints; every service is created
 * once per request.
 */

declare(strict_types=1);

use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\RouteCollection;
use App\Http\Router;
use App\I18n\Translator;
use App\Support\Config;
use App\Support\Paths;
use App\Theme\Theme;
use App\View\TranslationExtension;
use App\View\View;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\get;

return [
    'app.debug' => static fn (Config $config): bool => $config->bool('APP_DEBUG'),

    // PSR-17 factories (one Nyholm instance implements them all)
    Psr17Factory::class                  => autowire(),
    ResponseFactoryInterface::class      => get(Psr17Factory::class),
    StreamFactoryInterface::class        => get(Psr17Factory::class),
    ServerRequestFactoryInterface::class => get(Psr17Factory::class),
    UploadedFileFactoryInterface::class  => get(Psr17Factory::class),
    UriFactoryInterface::class           => get(Psr17Factory::class),

    // PSR-3 logger → var/log/app-YYYY-MM-DD.log, kept for 14 days
    LoggerInterface::class => static function (Paths $paths, ContainerInterface $c): LoggerInterface {
        $handler = new RotatingFileHandler($paths->var('log/app.log'), 14, $c->get('app.debug') ? Level::Debug : Level::Info);
        $handler->setFormatter(new LineFormatter(null, null, true, true));

        return new Logger('store', [$handler]);
    },

    RouteCollection::class => static function (Paths $paths): RouteCollection {
        $routes = new RouteCollection();
        (require $paths->root . '/config/routes.php')($routes);

        return $routes;
    },
    Router::class => autowire(),

    // Catalogs in translations/<locale>.php; js-keys.php lists the strings scripts need.
    Translator::class => autowire()->constructorParameter('directory', static fn (Paths $p) => $p->root . '/translations'),
    TranslationExtension::class => autowire()->constructorParameter('jsKeysFile', static fn (Paths $p) => $p->root . '/translations/js-keys.php'),

    Theme::class => autowire()->constructorParameter('cacheDir', static fn (Paths $p) => $p->var('cache/twig/theme')),
    View::class => autowire()
        ->constructorParameter('cacheDir', static fn (Paths $p) => $p->var('cache/twig/app'))
        ->constructorParameter('debug', get('app.debug')),

    ErrorHandlerMiddleware::class => autowire()->constructorParameter('debug', get('app.debug')),
];
