<?php

/**
 * Dependency-free test runner (composer test).
 *
 *   php tests/run.php                     every test in tests/Unit and tests/Integration
 *   php tests/run.php unit                only tests/Unit (or: integration)
 *   php tests/run.php --filter=Money      only classes or methods matching the text
 *   php tests/run.php -v                  list every test with its result
 *
 * Integration tests need a throwaway MariaDB database: set TEST_DB_NAME
 * (containing "test"), TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER and
 * TEST_DB_PASS. Without it they are reported as skipped.
 * Exit code: 0 when nothing failed, 1 otherwise.
 */

declare(strict_types=1);

use Tests\AssertionFailed;
use Tests\SkippedTest;
use Tests\TestCase;

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
$root = dirname(__DIR__);
// Warnings and notices fail the test; deprecations only when they come from our own code
// (newer PHP versions deprecate things that dependencies still use).
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $ours = !str_contains(str_replace('\\', '/', $file), '/vendor/');
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true) && !$ours) {
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Tests\\')) {
        $file = $root . '/tests/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$suites = [];
$filter = '';
$verbose = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    } elseif ($arg === '-v' || $arg === '--verbose') {
        $verbose = true;
    } elseif (in_array(strtolower($arg), ['unit', 'integration'], true)) {
        $suites[] = ucfirst(strtolower($arg));
    } else {
        fwrite(STDERR, "Unknown argument {$arg}\n");
        exit(2);
    }
}
$suites = $suites ?: ['Unit', 'Integration'];

// Discover tests/<Suite>/**/*Test.php → Tests\<Suite>\…\*Test
$classes = [];
foreach ($suites as $suite) {
    $dir = $root . '/tests/' . $suite;
    if (!is_dir($dir)) {
        continue;
    }
    $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)));
    ksort($files);
    foreach ($files as $path => $file) {
        if (!str_ends_with($path, 'Test.php')) {
            continue;
        }
        $relative = substr(str_replace('\\', '/', $path), strlen($root . '/tests/'), -4);
        $classes[] = 'Tests\\' . str_replace('/', '\\', $relative);
    }
}

$counts = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'assertions' => 0];
$failures = [];
$skips = [];
$started = microtime(true);

foreach ($classes as $class) {
    if (!class_exists($class) || !is_subclass_of($class, TestCase::class) || (new ReflectionClass($class))->isAbstract()) {
        continue;
    }
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test') || $method->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $name = substr($class, 6) . '::' . $method->getName();
        if ($filter !== '' && stripos($name, $filter) === false) {
            continue;
        }

        /** @var TestCase $test */
        $test = new $class();
        $result = 'pass';
        $detail = '';
        try {
            $test->setUp();
            try {
                $test->{$method->getName()}();
            } finally {
                $test->tearDown();
            }
        } catch (SkippedTest $e) {
            $result = 'skip';
            $detail = $e->getMessage();
        } catch (AssertionFailed $e) {
            $result = 'fail';
            $detail = $e->getMessage() . "\n    at " . location($e, $root);
        } catch (Throwable $e) {
            $result = 'fail';
            $detail = $e::class . ': ' . $e->getMessage() . "\n    at " . str_replace($root . DIRECTORY_SEPARATOR, '', $e->getFile()) . ':' . $e->getLine();
        }

        $counts[$result]++;
        $counts['assertions'] += $test->assertionCount();
        if ($result === 'fail') {
            $failures[] = "{$name}\n    {$detail}";
        } elseif ($result === 'skip') {
            $skips[$detail][] = $name;
        }
        if ($verbose) {
            echo str_pad(strtoupper($result), 5) . ' ' . $name . ($result === 'skip' ? " ({$detail})" : '') . PHP_EOL;
        } else {
            echo ['pass' => '.', 'fail' => 'F', 'skip' => 'S'][$result];
        }
    }
}

echo PHP_EOL . PHP_EOL;
foreach ($failures as $i => $failure) {
    echo ($i + 1) . ') ' . $failure . PHP_EOL . PHP_EOL;
}
foreach ($skips as $reason => $names) {
    echo 'Skipped ' . count($names) . " test(s): {$reason}" . PHP_EOL;
}
printf(
    "%s — %d passed, %d failed, %d skipped (%d assertions) in %.2fs\n",
    $counts['fail'] > 0 ? 'FAILED' : 'OK',
    $counts['pass'],
    $counts['fail'],
    $counts['skip'],
    $counts['assertions'],
    microtime(true) - $started
);

exit($counts['fail'] > 0 ? 1 : 0);

/** File:line of the test method that made the assertion. */
function location(Throwable $e, string $root): string
{
    foreach ($e->getTrace() as $frame) {
        $file = $frame['file'] ?? '';
        if ($file !== '' && str_contains(str_replace('\\', '/', $file), '/tests/') && !str_ends_with($file, 'TestCase.php')) {
            return str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ':' . ($frame['line'] ?? 0);
        }
    }

    return str_replace($root . DIRECTORY_SEPARATOR, '', $e->getFile()) . ':' . $e->getLine();
}
