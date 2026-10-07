<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Session;

/** Session-bound token protecting every state-changing form (CsrfMiddleware). */
final class Csrf
{
    public const FIELD = 'csrf_token';
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $submitted): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        return is_string($submitted) && is_string($token) && $token !== '' && hash_equals($token, $submitted);
    }
}
