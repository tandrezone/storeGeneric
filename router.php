<?php

/**
 * Router for PHP's built-in development server, mimicking production:
 * existing static files under public/ are served as-is, everything else
 * goes to the front controller. PHP files, theme templates (.twig) and
 * dotfiles are never served as static files.
 *
 * Usage: php -S localhost:8000 -t public router.php
 */

declare(strict_types=1);

$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = __DIR__ . '/public' . $path;

$private = preg_match('/\.(php|twig)$/', $file) === 1 || str_starts_with(basename($file), '.');
if ($path !== '/' && is_file($file) && !$private) {
    return false;
}

require __DIR__ . '/public/index.php';
