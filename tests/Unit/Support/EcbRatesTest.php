<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Infrastructure\EcbRates;
use RuntimeException;
use Tests\TestCase;

final class EcbRatesTest extends TestCase
{
    private const XML = "<?xml version=\"1.0\"?>\n<gesmes:Envelope xmlns:gesmes=\"x\" xmlns=\"y\"><Cube><Cube time='2026-11-04'>\n<Cube currency='USD' rate='1.1267'/>\n<Cube currency='JPY' rate='178.30'/>\n<Cube currency='GBP' rate='0.83460'/>\n</Cube></Cube></gesmes:Envelope>";

    public function testParsesTheDailyFeed(): void
    {
        $feed = EcbRates::parse(self::XML);
        $this->assertSame('2026-11-04', $feed['date']);
        $this->assertSame(['USD' => 1.1267, 'JPY' => 178.3, 'GBP' => 0.8346], $feed['rates']);
    }

    public function testEmptyFeedIsAnError(): void
    {
        $this->assertThrows(RuntimeException::class, fn () => EcbRates::parse('<html>maintenance</html>'), 'no rates');
    }
}
