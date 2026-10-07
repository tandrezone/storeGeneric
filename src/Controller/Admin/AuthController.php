<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Security\AdminAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminAuthenticator $auth,
    ) {
    }

    public function showLogin(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->isLoggedIn()) {
            return $this->responder->redirectToRoute('admin.products');
        }

        return $this->responder->view($request, 'admin/login.html.twig', ['error' => null, 'username' => '']);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        if ($this->auth->attempt($username, (string) ($body['password'] ?? ''), $ip)) {
            return $this->responder->redirectToRoute('admin.products');
        }

        return $this->responder->view($request, 'admin/login.html.twig', [
            'error' => 'Invalid username or password.',
            'username' => $username,
        ], 401);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout();

        return $this->responder->redirectToRoute('admin.login');
    }
}
