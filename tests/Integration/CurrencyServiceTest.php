<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\EcbRates;
use App\Repository\SettingRepository;
use App\Service\CurrencyService;
use RuntimeException;
use Tests\IntegrationTestCase;

final class FakeEcb extends EcbRates
{
    /** @param array<string, float> $rates */
    public function __construct(private array $rates, private bool $fail = false)
    {
    }

    public function fetch(): array
    {
        if ($this->fail) {
            throw new RuntimeException('Could not download the ECB rates: offline');
        }

        return ['date' => '2026-11-04', 'rates' => $this->rates];
    }
}

final class CurrencyServiceTest extends IntegrationTestCase
{
    public function tearDown(): void
    {
        $settings = $this->get(SettingRepository::class);
        foreach (['currency_rates', 'currency_rates_auto', 'currency_rates_date'] as $k) {
            $settings->set($k, null);
        }
        $_SESSION = [];
        parent::tearDown();
    }

    public function testParsingRatesAndRejectingBadLines(): void
    {
        [$rates, $problems] = CurrencyService::parseRates("usd=1,10 ; GBP = 0.85,JPY=178\nCHF=0,95", 'EUR');
        $this->assertSame(['USD' => 1.1, 'GBP' => 0.85, 'JPY' => 178.0, 'CHF' => 0.95], $rates, 'decimal commas, and commas / semicolons / newlines between entries');
        $this->assertSame([], $problems);

        foreach (['EUR=1' => 'own currency', 'USD=0' => 'zero', 'XX=1.2' => 'short code', 'USD=abc' => 'text', 'ok' => 'no equals sign', 'USD=1.2.3' => 'two points'] as $line => $why) {
            $this->assertCount(1, CurrencyService::parseRates($line, 'EUR')[1], $why);
        }
        $this->assertNotSame([], CurrencyService::parseRates('USD=1.1, GBP=oops', 'EUR')[1], 'one bad entry spoils the list');
    }

    public function testChoosingConvertingAndFormatting(): void
    {
        $service = $this->get(CurrencyService::class);
        $this->assertSame([], $service->saveRates('USD=1.10, JPY=160', false));
        $this->assertSame(['USD', 'JPY'], $service->available());

        $this->assertNull($service->chosen());
        $this->assertSame('', $service->approx(10.0), 'nothing chosen, nothing shown');

        $service->choose('usd');
        $this->assertSame('USD', $service->chosen());
        $this->assertSame(13.75, $service->convert(12.5, 'USD'));
        $this->assertStringContainsString('13.75', $service->approx(12.5));
        $this->assertTrue(str_starts_with($service->approx(12.5), '≈'));

        $service->choose('JPY');
        $this->assertSame(2000.0, $service->convert(12.5, 'JPY'), 'yen has no decimals');

        $service->choose('XYZ'); // unknown: clears
        $this->assertNull($service->chosen());
        $service->choose('USD');
        $service->saveRates('GBP=0.85', false);
        $this->assertNull($service->chosen(), 'a currency that is no longer offered is forgotten');
    }

    public function testBadInputSavesNothing(): void
    {
        $service = $this->get(CurrencyService::class);
        $service->saveRates('USD=1.10', true);
        $this->assertNotSame([], $service->saveRates('USD=2, nonsense', false));
        $this->assertSame(['USD' => 1.1], $service->rates());
        $this->assertTrue($service->autoUpdate());
    }

    public function testEcbUpdateKeepsYourListAndTypedRatesForCurrenciesTheEcbLacks(): void
    {
        $service = $this->get(CurrencyService::class);
        $service->saveRates('USD=1.00, GBP=0.80, XAU=0.0005', true);
        $count = $service->updateFromEcb(new FakeEcb(['USD' => 1.1267, 'GBP' => 0.8346, 'SEK' => 10.97]));
        $this->assertSame(2, $count);
        $this->assertSame(['USD' => 1.1267, 'GBP' => 0.8346, 'XAU' => 0.0005], $service->rates());
        $this->assertSame('2026-11-04', $service->updatedOn());
    }

    public function testEcbUpdateErrors(): void
    {
        $service = $this->get(CurrencyService::class);
        $this->assertThrows(RuntimeException::class, fn () => $service->updateFromEcb(new FakeEcb([])), 'Add the currencies');
        $service->saveRates('USD=1.1', false);
        $this->assertThrows(RuntimeException::class, fn () => $service->updateFromEcb(new FakeEcb([], true)), 'offline');
        $this->assertSame(['USD' => 1.1], $service->rates(), 'a failed update changes nothing');
    }
}
