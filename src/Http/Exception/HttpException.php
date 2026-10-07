<?php

declare(strict_types=1);

namespace App\Http\Exception;

use RuntimeException;

/** An error that maps directly to an HTTP status (rendered by ErrorHandlerMiddleware). */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Page not found.'): self
    {
        return new self(404, $message);
    }

    public static function badRequest(string $message = 'Bad request.'): self
    {
        return new self(400, $message);
    }
}
