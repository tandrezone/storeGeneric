<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database;
use App\Repository\SettingRepository;
use App\Service\TaxSettings;
use App\Service\VatIdService;
use RuntimeException;
use Tests\FakeVies;
use Tests\IntegrationTestCase;

final class VatIdServiceTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('DELETE FROM vat_checks');
        $settings = $this->get(SettingRepository::class);
        $settings->set('tax_enabled', '1');
        $settings->set('company_country', 'PT');
    }

    public function tearDown(): void
    {
        $settings = $this->get(SettingRepository::class);
        $settings->set('tax_enabled', null);
        $settings->set('company_country', null);
        parent::tearDown();
    }

    /** @param list<array{status: int, body: array<mixed>}|\Throwable> $queue */
    private function service(array $queue): array
    {
        $http = new FakeVies($queue);

        return [new VatIdService($this->get(Database::class), $http, $this->get(SettingRepository::class), $this->get(TaxSettings::class), $this->get(\App\I18n\Translator::class)), $http];
    }

    private function valid(string $name = 'ACME SL'): array
    {
        return ['status' => 200, 'body' => ['isValid' => true, 'name' => $name, 'address' => "CALLE 1\n28001 MADRID", 'userError' => 'VALID']];
    }

    public function testParsing(): void
    {
        $this->assertSame(['ES', 'B12345678', 'ESB12345678'], VatIdService::parse(' es b-123.456 78 '));
        $this->assertSame(['GR', '123456789', 'EL123456789'], VatIdService::parse('EL 123456789'));
        $this->assertNull(VatIdService::parse('GB123456789'), 'not an EU state');
        $this->assertNull(VatIdService::parse('123456789'));
        $this->assertNull(VatIdService::parse('E'));
        $this->assertNull(VatIdService::parse(''));
    }

    public function testValidNumberIsCachedSoVIESIsAskedOnce(): void
    {
        [$service, $http] = $this->service([$this->valid()]);
        $first = $service->check('ESB12345678');
        $this->assertSame('valid', $first['status']);
        $this->assertSame('ACME SL', $first['name']);
        $this->assertStringContainsString('/ms/ES/vat/B12345678', $http->calls[0]);

        $second = $service->check('es b12345678');
        $this->assertSame('valid', $second['status']);
        $this->assertCount(1, $http->calls, 'second check came from the cache');
    }

    public function testInvalidIsAnAnswerButOutagesAreNotCached(): void
    {
        [$service, $http] = $this->service([
            ['status' => 200, 'body' => ['isValid' => false, 'userError' => 'INVALID']],
            ['status' => 200, 'body' => ['isValid' => false, 'userError' => 'MS_UNAVAILABLE']],
            new RuntimeException('timeout'),
            $this->valid(),
        ]);
        $this->assertSame('invalid', $service->check('ESB11111111')['status']);
        $this->assertSame('invalid', $service->check('ESB11111111')['status'], 'cached');
        $this->assertSame('unavailable', $service->check('ESB22222222')['status']);
        $this->assertSame('unavailable', $service->check('ESB22222222')['status'], 'not cached: asked again');
        $this->assertSame('valid', $service->check('ESB22222222')['status']);
        $this->assertCount(4, $http->calls);
    }

    public function testReverseChargeRule(): void
    {
        // Seller PT. Spanish business, goods to Spain → reverse charge.
        [$service] = $this->service([$this->valid()]);
        $r = $service->forCheckout('ESB12345678', 'Spain');
        $this->assertTrue($r['reverse_charge']);
        $this->assertSame('ESB12345678', $r['vat_id']);

        // Same number, goods to Portugal → VAT still charged (note shown).
        $r = $service->forCheckout('ESB12345678', 'Portugal');
        $this->assertFalse($r['reverse_charge']);
        $this->assertNotNull($r['message']);

        // A Portuguese number: the seller's own country, never reverse charge.
        [$service] = $this->service([$this->valid()]);
        $this->assertFalse($service->forCheckout('PT123456789', 'Portugal')['reverse_charge']);

        // Goods outside the EU.
        $this->assertFalse($service->forCheckout('ESB12345678', 'United States')['reverse_charge']);
    }

    public function testEmptyNumberOrDisabledFeatureDoesNothingAndNeverCallsVIES(): void
    {
        [$service, $http] = $this->service([]);
        $this->assertFalse($service->forCheckout('', 'Spain')['reverse_charge']);
        $this->get(SettingRepository::class)->set('company_country', null);
        $this->assertFalse($service->enabled());
        $this->assertSame('', $service->forCheckout('ESB12345678', 'Spain')['vat_id']);
        $this->assertSame([], $http->calls);
    }

    public function testInvalidOrUnavailableBlocksCheckoutWithAMessage(): void
    {
        [$service] = $this->service([
            ['status' => 200, 'body' => ['isValid' => false, 'userError' => 'INVALID']],
            new RuntimeException('down'),
        ]);
        $this->assertThrows(RuntimeException::class, fn () => $service->forCheckout('ESB11111111', 'Spain'), 'not valid');
        $this->assertThrows(RuntimeException::class, fn () => $service->forCheckout('ESB33333333', 'Spain'), 'try again');
    }
}
