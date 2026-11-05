<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Config;
use App\Support\FileCache;
use App\Support\Paths;
use Tests\TestCase;

final class FileCacheTest extends TestCase
{
    private string $root = '';

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/filecache-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var', 0777, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->root . '/var/cache/data/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/var/cache/data');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    private function cache(array $env = []): FileCache
    {
        return new FileCache(new Paths($this->root, $this->root), Config::fromArray($env));
    }

    public function testComputesOnceThenServesFromTheCache(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $compute = function () use (&$calls): array {
            $calls++;

            return ['rows' => [['id' => 1, 'name' => 'Té — ç'], ['id' => 2, 'price' => '4.50']], 'n' => 2];
        };
        $first = $cache->remember('k', 60, $compute);
        $second = $cache->remember('k', 60, $compute);
        $this->assertSame(1, $calls);
        $this->assertSame($first, $second, 'types survive the round trip');
        $this->assertSame(2, $cache->remember('other', 60, fn (): int => 2), 'keys are separate');
    }

    public function testExpiryAndBump(): void
    {
        $cache = $this->cache();
        $n = 0;
        $this->assertSame(1, $cache->remember('k', 60, function () use (&$n): int { return ++$n; }));
        $cache->bump();
        $this->assertSame(2, $cache->remember('k', 60, function () use (&$n): int { return ++$n; }), 'bump drops everything');
        $this->assertSame(2, $cache->remember('k', 60, function () use (&$n): int { return ++$n; }));

        // An entry whose time is up is recomputed.
        foreach (glob($this->root . '/var/cache/data/*.json') as $file) {
            $entry = json_decode((string) file_get_contents($file), true);
            $entry['expires'] = time() - 1;
            file_put_contents($file, json_encode($entry));
        }
        $this->assertSame(3, $cache->remember('k', 60, function () use (&$n): int { return ++$n; }));
    }

    public function testDisabledByConfigOrDebugJustComputes(): void
    {
        foreach ([['CACHE_ENABLED' => 'false'], ['APP_DEBUG' => 'true']] as $env) {
            $cache = $this->cache($env);
            $this->assertFalse($cache->enabled());
            $n = 0;
            $cache->remember('k', 60, function () use (&$n): int { return ++$n; });
            $cache->remember('k', 60, function () use (&$n): int { return ++$n; });
            $this->assertSame(2, $n);
        }
        $this->assertSame([], glob($this->root . '/var/cache/data/*.json') ?: []);
    }

    public function testCorruptOrUnwritableCacheIsHarmless(): void
    {
        $cache = $this->cache();
        $cache->remember('k', 60, fn (): int => 1);
        foreach (glob($this->root . '/var/cache/data/*.json') as $file) {
            file_put_contents($file, '{not json');
        }
        $this->assertSame(5, $cache->remember('k', 60, fn (): int => 5), 'a damaged entry is recomputed');

        $broken = new FileCache(new Paths('/proc/definitely/not/writable', '/proc/definitely/not/writable'), Config::fromArray([]));
        $this->assertSame(7, $broken->remember('k', 60, fn (): int => 7));
    }

    public function testNullAndFalseValuesAreCachedToo(): void
    {
        $cache = $this->cache();
        $n = 0;
        foreach (range(1, 3) as $_) {
            $cache->remember('nothing', 60, function () use (&$n): ?string { $n++; return null; });
        }
        $this->assertSame(1, $n);
    }
}
