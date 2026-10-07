<?php

declare(strict_types=1);

namespace Tests;

/** Thrown by TestCase::skip() — the test is reported as skipped, not failed. */
final class SkippedTest extends \RuntimeException
{
}
