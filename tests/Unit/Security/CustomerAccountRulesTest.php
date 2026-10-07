<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Controller\Shop\AccountAuthController;
use App\Repository\CustomerRepository;
use App\Security\CustomerAuthenticator;
use Tests\TestCase;

final class CustomerAccountRulesTest extends TestCase
{
    public function testPasswordRules(): void
    {
        $this->assertNotNull(CustomerAuthenticator::passwordProblem('short'));
        $this->assertNull(CustomerAuthenticator::passwordProblem('long enough'));
        $this->assertNull(CustomerAuthenticator::passwordProblem(str_repeat('a', 72)));
        $this->assertNotNull(CustomerAuthenticator::passwordProblem(str_repeat('a', 73)), 'bcrypt ignores bytes after 72');
        $this->assertNotNull(CustomerAuthenticator::passwordProblem(str_repeat('é', 37)), '74 bytes');
    }

    public function testEmailsAreNormalised(): void
    {
        $this->assertSame('ana@example.com', CustomerRepository::normalizeEmail('  Ana@Example.COM '));
    }

    public function testReturnPathMustBeLocal(): void
    {
        foreach (['/checkout', '/account/orders?page=2', '/'] as $ok) {
            $this->assertTrue(AccountAuthController::isLocalPath($ok), $ok);
        }
        foreach (['', 'checkout', '//evil.example', '/\\evil.example', 'https://evil.example/', "/x\r\nLocation: y", 'javascript:alert(1)'] as $bad) {
            $this->assertFalse(AccountAuthController::isLocalPath($bad), $bad);
        }
    }
}
