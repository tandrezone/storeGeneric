<?php

declare(strict_types=1);

namespace Tests;

use App\Infrastructure\HttpClient;

/** A VIES client answering from a queue (a Throwable in the queue is thrown, like a network failure). */
final class FakeVies extends HttpClient
{
    /** @var list<string> urls asked */
    public array $calls = [];

    /** @param list<array{status: int, body: array<mixed>}|\Throwable> $queue */
    public function __construct(private array $queue)
    {
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, bool $form = false, int $timeout = 20): array
    {
        $this->calls[] = $url;
        $next = array_shift($this->queue) ?? throw new \LogicException('unexpected VIES call');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
