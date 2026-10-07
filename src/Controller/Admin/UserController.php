<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\Repository\AdminUserRepository;
use App\Security\AdminAuthenticator;
use App\Security\AdminRole;
use App\Service\AuditLog;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Admin → Users (owners only): create admins, change role / email,
 * activate / deactivate, reset passwords. The last active owner can't be
 * demoted or deactivated, and nobody can demote or deactivate themselves.
 */
final class UserController
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._@-]{3,60}$/';

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly AdminUserRepository $users,
        private readonly AdminAuthenticator $auth,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $me = (array) $this->auth->user();

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'create':
                    $this->create($request, $me, $body);
                    break;

                case 'update':
                    $this->update($request, $me, $this->target($body), $body);
                    break;

                case 'set_active':
                    $this->setActive($request, $me, $this->target($body), !empty($body['active']));
                    break;

                case 'reset_password':
                    $this->resetPassword($request, $me, $this->target($body), $body);
                    break;
            }
        } catch (RuntimeException $e) {
            return $this->page($request, [$e->getMessage()], 422, $body);
        }

        return $this->responder->redirectToRoute('admin.users');
    }

    /**
     * @param array<string, mixed> $me
     * @param array<string, mixed> $body
     */
    private function create(ServerRequestInterface $request, array $me, array $body): void
    {
        $username = trim((string) ($body['username'] ?? ''));
        $email = $this->email($body);
        $role = $this->role($body);
        $password = (string) ($body['password'] ?? '');

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new RuntimeException('Usernames are 3–60 characters: letters, digits and . _ @ -');
        }
        $this->checkNewPassword($password, (string) ($body['password_confirm'] ?? ''));
        if ($this->users->findByUsername($username) !== null) {
            throw new RuntimeException("The username \"{$username}\" is already taken.");
        }

        try {
            $id = $this->users->create($username, $email, password_hash($password, PASSWORD_DEFAULT), $role->value);
        } catch (PDOException) {
            throw new RuntimeException("The username \"{$username}\" is already taken.");
        }

        $this->audit->record($request, $me, 'user.create', 'admin_user', $id, "Created {$role->value} {$username}", [
            'username' => $username, 'email' => $email, 'role' => $role->value,
        ]);
        $this->session->flash('success', "User {$username} created.");
    }

    /**
     * @param array<string, mixed> $me
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     */
    private function update(ServerRequestInterface $request, array $me, array $user, array $body): void
    {
        $email = $this->email($body);
        $role = $this->role($body);
        $oldRole = (string) $user['role'];

        if ($role->value !== $oldRole) {
            if ((int) $user['id'] === (int) $me['id']) {
                throw new RuntimeException("You can't change your own role.");
            }
            $this->guardLastOwner($user);
        }

        $this->users->update((int) $user['id'], $email, $role->value);

        $changes = [];
        if ($role->value !== $oldRole) {
            $changes['role'] = ['from' => $oldRole, 'to' => $role->value];
        }
        if ($email !== ($user['email'] ?? null)) {
            $changes['email'] = ['from' => $user['email'], 'to' => $email];
        }
        if ($changes !== []) {
            $this->audit->record($request, $me, 'user.update', 'admin_user', (int) $user['id'], "Updated {$user['username']}: " . implode(', ', array_keys($changes)), $changes);
        }
        $this->session->flash('success', "User {$user['username']} saved.");
    }

    /**
     * @param array<string, mixed> $me
     * @param array<string, mixed> $user
     */
    private function setActive(ServerRequestInterface $request, array $me, array $user, bool $active): void
    {
        if (!$active) {
            if ((int) $user['id'] === (int) $me['id']) {
                throw new RuntimeException("You can't deactivate yourself.");
            }
            $this->guardLastOwner($user);
        }

        $this->users->setActive((int) $user['id'], $active);
        $verb = $active ? 'activate' : 'deactivate';
        $this->audit->record($request, $me, 'user.' . $verb, 'admin_user', (int) $user['id'], ucfirst($verb) . "d {$user['username']}");
        $this->session->flash('success', "User {$user['username']} " . ($active ? 'activated.' : 'deactivated — they are logged out on their next request.'));
    }

    /**
     * @param array<string, mixed> $me
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     */
    private function resetPassword(ServerRequestInterface $request, array $me, array $user, array $body): void
    {
        if ((int) $user['id'] === (int) $me['id']) {
            throw new RuntimeException('Change your own password in My account (it asks for your current password).');
        }
        $password = (string) ($body['password'] ?? '');
        $this->checkNewPassword($password, (string) ($body['password_confirm'] ?? ''));

        $this->auth->changePassword((int) $user['id'], $password);
        $this->audit->record($request, $me, 'user.reset_password', 'admin_user', (int) $user['id'], "Reset the password of {$user['username']}");
        $this->session->flash('success', "Password of {$user['username']} changed — their open sessions end on their next request.");
    }

    /**
     * Refuses to demote or deactivate the only active owner.
     *
     * @param array<string, mixed> $user
     */
    private function guardLastOwner(array $user): void
    {
        if ($user['role'] === AdminRole::Owner->value && (bool) $user['active'] && $this->users->countActiveOwners() <= 1) {
            throw new RuntimeException("{$user['username']} is the only active owner. Make someone else an owner first.");
        }
    }

    private function checkNewPassword(string $password, string $confirm): void
    {
        $problem = AdminAuthenticator::passwordProblem($password);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException("The two passwords don't match.");
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function target(array $body): array
    {
        $user = $this->users->find((int) ($body['id'] ?? 0));
        if ($user === null) {
            throw new RuntimeException('That user no longer exists.');
        }
        unset($user['password_hash']);

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function email(array $body): ?string
    {
        $email = trim((string) ($body['email'] ?? ''));
        if ($email === '') {
            return null;
        }
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter a valid email address (or leave it empty).');
        }

        return $email;
    }

    /** @param array<string, mixed> $body */
    private function role(array $body): AdminRole
    {
        return AdminRole::tryFrom((string) ($body['role'] ?? '')) ?? throw new RuntimeException('Choose a role.');
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed>|null $input
     */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200, ?array $input = null): ResponseInterface
    {
        if ($input !== null) {
            unset($input['password'], $input['password_confirm'], $input['csrf_token']);
        }

        return $this->responder->view($request, 'admin/users.html.twig', [
            'users'        => $this->users->findAll(),
            'roles'        => AdminRole::values(),
            'me'           => $this->auth->user(),
            'errors'       => $errors,
            'input'        => $input,
            'min_password' => AdminAuthenticator::MIN_PASSWORD_LENGTH,
        ], $status);
    }
}
