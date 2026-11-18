<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Payment\PaymentSettings;
use App\Repository\SettingRepository;
use App\Support\Config;
use RuntimeException;
use Tests\IntegrationTestCase;

final class PaymentSettingsTest extends IntegrationTestCase
{
    public function tearDown(): void
    {
        // Saved keys would leak into later tests that share this database.
        $this->pdo()->exec("DELETE FROM settings WHERE name LIKE 'payment.%'");
        parent::tearDown();
    }

    public function testSecretsAreEncryptedAndWinOverEnv(): void
    {
        $settings = $this->get(PaymentSettings::class);
        $settings->save('stripe', ['enabled' => '1', 'secret' => ['STRIPE_SECRET_KEY' => 'sk_test_abc123', 'STRIPE_WEBHOOK_SECRET' => 'whsec_xyz']]);

        $stored = (string) $this->get(SettingRepository::class)->get('payment.STRIPE_SECRET_KEY');
        $this->assertStringNotContainsString('sk_test_abc123', $stored);

        $config = $settings->config(Config::fromArray(['STRIPE_SECRET_KEY' => 'sk_env', 'PAYMENT_STRIPE_ENABLED' => 'false']));
        $this->assertSame('sk_test_abc123', $config->get('STRIPE_SECRET_KEY'));
        $this->assertTrue($config->bool('PAYMENT_STRIPE_ENABLED'));
    }

    public function testEmptySecretKeepsTheSavedOneAndClearRemovesIt(): void
    {
        $settings = $this->get(PaymentSettings::class);
        $settings->save('oxapay', ['enabled' => '1', 'secret' => ['OXAPAY_MERCHANT_KEY' => 'merchant-1']]);
        $settings->save('oxapay', ['enabled' => '1', 'secret' => ['OXAPAY_MERCHANT_KEY' => '']]);
        $this->assertSame('merchant-1', $settings->config(Config::fromArray([]))->get('OXAPAY_MERCHANT_KEY'));

        $settings->save('oxapay', ['enabled' => '1', 'clear' => ['OXAPAY_MERCHANT_KEY' => '1']]);
        $this->assertFalse($settings->config(Config::fromArray(['OXAPAY_MERCHANT_KEY' => 'from-env']))->has('OXAPAY_MERCHANT_KEY'));
    }

    public function testUnsavedKeysFallBackToEnv(): void
    {
        $config = $this->get(PaymentSettings::class)->config(Config::fromArray(['PAYPAL_CLIENT_ID' => 'env-id']));
        $this->assertSame('env-id', $config->get('PAYPAL_CLIENT_ID'));
    }

    public function testInvalidValuesAreRejectedAndNothingIsSaved(): void
    {
        $settings = $this->get(PaymentSettings::class);
        $this->assertThrows(RuntimeException::class, fn () => $settings->save('stripe', ['enabled' => '1', 'secret' => ['STRIPE_SECRET_KEY' => 'pk_live_wrong']]));
        $this->assertThrows(RuntimeException::class, fn () => $settings->save('bank_transfer', ['enabled' => '1', 'field' => ['BANK_IBAN' => 'not an iban']]));
        $this->assertNull($this->get(SettingRepository::class)->get('payment.PAYMENT_BANK_TRANSFER_ENABLED'));
    }
}
