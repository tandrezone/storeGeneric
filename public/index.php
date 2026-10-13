<?php

/**
 * Front controller: every page and endpoint is served from here
 * (see config/routes.php). Static files are served directly by the web server.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

App\Kernel::boot(dirname(__DIR__))->run();
