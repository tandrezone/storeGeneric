<?php

declare(strict_types=1);

namespace Tests;

use Throwable;

/**
 * Base class for tests run by tests/run.php — a small, dependency-free
 * stand-in for PHPUnit (Packagist isn't used by this project). Every public
 * method whose name starts with "test" is run on a fresh instance, between
 * setUp() and tearDown().
 */
abstract class TestCase
{
    private int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    protected function skip(string $reason): never
    {
        throw new SkippedTest($reason);
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->check($actual === true, $message ?: 'Expected true, got ' . $this->export($actual));
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->check($actual === false, $message ?: 'Expected false, got ' . $this->export($actual));
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->check($actual === null, $message ?: 'Expected null, got ' . $this->export($actual));
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->check($actual !== null, $message ?: 'Expected a value, got null');
    }

    /** Strict comparison (===). */
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected === $actual, $this->prefix($message) . 'Expected ' . $this->export($expected) . ', got ' . $this->export($actual));
    }

    /** Loose comparison (==), e.g. "12.50" vs 12.5. */
    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected == $actual, $this->prefix($message) . 'Expected ' . $this->export($expected) . ', got ' . $this->export($actual));
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->check($unexpected !== $actual, $this->prefix($message) . 'Did not expect ' . $this->export($actual));
    }

    /** @param array<mixed>|\Countable $haystack */
    protected function assertCount(int $expected, array|\Countable $haystack, string $message = ''): void
    {
        $this->check(count($haystack) === $expected, $this->prefix($message) . "Expected {$expected} element(s), got " . count($haystack));
    }

    /** @param array<mixed> $haystack */
    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->check(in_array($needle, $haystack, true), $this->prefix($message) . $this->export($needle) . ' not found in ' . $this->export($haystack));
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(str_contains($haystack, $needle), $this->prefix($message) . $this->export($needle) . ' not found in ' . $this->export($haystack));
    }

    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(!str_contains($haystack, $needle), $this->prefix($message) . $this->export($needle) . ' unexpectedly found in ' . $this->export($haystack));
    }

    /**
     * Runs $fn and checks it throws $class (and, when given, a message containing $messagePart).
     *
     * @param class-string<Throwable> $class
     */
    protected function assertThrows(string $class, callable $fn, string $messagePart = ''): Throwable
    {
        try {
            $fn();
        } catch (AssertionFailed | SkippedTest $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->check($e instanceof $class, 'Expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage(), 'Exception message');
            }

            return $e;
        }
        $this->fail("Expected {$class} to be thrown");
    }

    private function check(bool $ok, string $message): void
    {
        $this->assertions++;
        if (!$ok) {
            throw new AssertionFailed($message);
        }
    }

    private function prefix(string $message): string
    {
        return $message === '' ? '' : $message . ': ';
    }

    private function export(mixed $value): string
    {
        $text = var_export($value, true);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
    }
}
