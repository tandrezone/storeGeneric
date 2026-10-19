<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\AdminUserRepository;
use App\Security\AdminAuthenticator;
use App\Security\Totp;
use App\Service\AuditLog;
use Nyholm\Psr7\ServerRequest;
use Tests\IntegrationTestCase;

final class AdminTwoFactorTest extends IntegrationTestCase
{
    private const PASSWORD = 'correct horse battery';

    public function setUp(): void
    {
        parent::setUp();
        $this->get(AdminAuthenticator::class)->logout();
    }

    public function tearDown(): void
    {
        $this->get(AdminAuthenticator::class)->logout();
    }

    public function testUsersWithoutTwoFactorLogInWithThePasswordAlone(): void
    {
        $user = $this->makeUser();
        $auth = $this->get(AdminAuthenticator::class);

        $this->assertTrue($auth->attempt($user['username'], self::PASSWORD, $this->ip()));
        $this->assertSame($user['id'], (int) $auth->user()['id']);
        $this->assertNull($auth->pendingSecondFactor());
    }

    public function testEnablingNeedsAValidCodeAndGivesRecoveryCodes(): void
    {
        $user = $this->makeUser();
        $auth = $this->get(AdminAuthenticator::class);
        $secret = Totp::generateSecret();
        $now = Totp::timeStep(time());

        $this->assertNull($auth->enableTwoFactor($user['id'], $secret, '000000'), 'wrong code');
        $this->assertSame(0, (int) $this->row($user['id'])['totp_enabled']);

        $codes = $auth->enableTwoFactor($user['id'], $secret, Totp::code($secret, $now - 1));
        $this->assertNotNull($codes);
        $this->assertCount(8, $codes);
        $row = $this->row($user['id']);
        $this->assertSame(1, (int) $row['totp_enabled']);
        $this->assertSame($secret, $row['totp_secret']);
        $this->assertSame($now - 1, (int) $row['totp_last_step']);
        $this->assertSame(8, $this->get(AdminUserRepository::class)->countUnusedRecoveryCodes($user['id']));

        $stored = $this->pdo()->query('SELECT code_hash FROM admin_recovery_codes WHERE user_id = ' . $user['id'])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($codes as $code) {
            $this->assertFalse(in_array(str_replace('-', '', $code), $stored, true), 'only hashes are stored');
        }
    }

    public function testLoginNeedsTheCodeAndRefusesReplay(): void
    {
        [$user, $secret] = $this->makeTwoFactorUser();
        $auth = $this->get(AdminAuthenticator::class);
        $ip = $this->ip();

        $this->assertFalse($auth->attempt($user['username'], self::PASSWORD, $ip), 'password alone is not enough');
        $this->assertSame('second_factor', $auth->lastFailure());
        $this->assertNull($auth->user(), 'not authenticated yet');
        $this->assertSame($user['id'], $auth->pendingSecondFactor()['id']);

        $this->assertFalse($auth->completeSecondFactor('000000', $ip));
        $this->assertSame('invalid', $auth->lastFailure());
        $this->assertNull($auth->user());

        $code = Totp::code($secret, Totp::timeStep(time()));
        $this->assertTrue($auth->completeSecondFactor($code, $ip));
        $this->assertSame('totp', $auth->lastFactor());
        $this->assertSame($user['id'], (int) $auth->user()['id']);
        $this->assertNull($auth->pendingSecondFactor());

        // The same code can't be used again, not even for a new login.
        $auth->logout();
        $this->assertFalse($auth->attempt($user['username'], self::PASSWORD, $ip));
        $this->assertFalse($auth->completeSecondFactor($code, $ip), 'replayed code');
        $this->assertNull($auth->user());
    }

    public function testWrongPasswordNeverReachesTheCodeStep(): void
    {
        [$user] = $this->makeTwoFactorUser();
        $auth = $this->get(AdminAuthenticator::class);

        $this->assertFalse($auth->attempt($user['username'], 'wrong password', $this->ip()));
        $this->assertSame('invalid', $auth->lastFailure());
        $this->assertNull($auth->pendingSecondFactor());
        $this->assertFalse($auth->completeSecondFactor('123456', $this->ip()));
        $this->assertSame('expired', $auth->lastFailure());
    }

    public function testRecoveryCodeWorksOnce(): void
    {
        [$user, , $codes] = $this->makeTwoFactorUser();
        $auth = $this->get(AdminAuthenticator::class);
        $ip = $this->ip();

        $auth->attempt($user['username'], self::PASSWORD, $ip);
        $this->assertTrue($auth->completeSecondFactor(strtolower($codes[0]), $ip));
        $this->assertSame('recovery', $auth->lastFactor());
        $this->assertSame(7, $this->get(AdminUserRepository::class)->countUnusedRecoveryCodes($user['id']));

        $auth->logout();
        $auth->attempt($user['username'], self::PASSWORD, $ip);
        $this->assertFalse($auth->completeSecondFactor($codes[0], $ip), 'already used');
        $this->assertTrue($auth->completeSecondFactor($codes[1], $ip), 'another one still works');
    }

    public function testCodeStepIsRateLimited(): void
    {
        [$user, , $codes] = $this->makeTwoFactorUser();
        $auth = $this->get(AdminAuthenticator::class);
        $ip = $this->ip();

        $auth->attempt($user['username'], self::PASSWORD, $ip); // attempt 1
        for ($i = 0; $i < 4; $i++) {                            // attempts 2-5
            $this->assertFalse($auth->completeSecondFactor('00000' . $i, $ip));
            $this->assertSame('invalid', $auth->lastFailure());
        }
        $this->assertFalse($auth->completeSecondFactor($codes[0], $ip), 'valid recovery code, but locked out');
        $this->assertSame('locked', $auth->lastFailure());
        $this->assertNull($auth->user());
    }

    public function testDisableAndOwnerResetClearEverything(): void
    {
        [$user] = $this->makeTwoFactorUser();
        $auth = $this->get(AdminAuthenticator::class);
        $users = $this->get(AdminUserRepository::class);

        $auth->disableTwoFactor($user['id']);
        $row = $this->row($user['id']);
        $this->assertSame(0, (int) $row['totp_enabled']);
        $this->assertNull($row['totp_secret']);
        $this->assertNull($row['totp_last_step']);
        $this->assertSame(0, $users->countUnusedRecoveryCodes($user['id']));
        $this->assertTrue($auth->attempt($user['username'], self::PASSWORD, $this->ip()), 'password only again');
        $auth->logout();

        [$other] = $this->makeTwoFactorUser();
        $users->clearTotp($other['id']); // what Users → reset and admin:user do
        $this->assertSame(0, (int) $this->row($other['id'])['totp_enabled']);
        $this->assertSame(0, $users->countUnusedRecoveryCodes($other['id']));
    }

    public function testSecretsNeverReachTheActivityLog(): void
    {
        $secret = Totp::generateSecret();
        $details = AuditLog::redact([
            'action' => '2fa_enable', 'totp_code' => '123456', 'totp_secret' => $secret,
            'recovery_code' => 'ABCDE-FGHIJ', 'recovery_codes' => ['ABCDE-FGHIJ'],
        ]);
        $this->assertSame(['action' => '2fa_enable'], $details);

        $user = $this->makeUser();
        $this->get(AuditLog::class)->record(
            new ServerRequest('POST', '/admin/account'), ['id' => $user['id'], 'username' => $user['username']],
            'account.2fa_enable', 'admin_user', $user['id'], 'Turned on two-factor login',
            ['totp_secret' => $secret, 'totp_code' => '123456']
        );
        $stmt = $this->pdo()->prepare('SELECT details, summary FROM admin_audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user['id']]);
        $entry = $stmt->fetch();
        $this->assertStringNotContainsString($secret, (string) $entry['details'] . $entry['summary']);
        $this->assertStringNotContainsString('123456', (string) $entry['details'] . $entry['summary']);
    }

    /** @return array{id: int, username: string} */
    private function makeUser(): array
    {
        $username = 'tfa-' . bin2hex(random_bytes(4));
        $id = $this->get(AdminUserRepository::class)->create($username, null, password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'staff');

        return ['id' => $id, 'username' => $username];
    }

    /** @return array{0: array{id: int, username: string}, 1: string, 2: list<string>} user, secret, recovery codes */
    private function makeTwoFactorUser(): array
    {
        $user = $this->makeUser();
        $secret = Totp::generateSecret();
        // Confirmed with the previous step's code, so the current step is still unused for the login.
        $codes = $this->get(AdminAuthenticator::class)->enableTwoFactor($user['id'], $secret, Totp::code($secret, Totp::timeStep(time()) - 1));
        $this->assertNotNull($codes);

        return [$user, $secret, $codes];
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->get(AdminUserRepository::class)->find($id);
    }

    /** A fresh IP per call, so rate limits of one test don't leak into another. */
    private function ip(): string
    {
        return '198.51.100.' . random_int(1, 254) . '-' . bin2hex(random_bytes(3));
    }
}
