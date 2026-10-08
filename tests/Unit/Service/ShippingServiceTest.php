<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\ShippingMethodRepository;
use App\Service\ShippingService;
use App\Support\Config;
use Tests\TestCase;

final class ShippingServiceTest extends TestCase
{
    private ShippingService $shipping;

    public function setUp(): void
    {
        // The repository is never queried by these methods (the connection is lazy).
        $this->shipping = new ShippingService(
            new ShippingMethodRepository(new Database(Config::fromArray([]))),
            new Translator(dirname(__DIR__, 3) . '/translations'),
        );
    }

    public function testFlatCost(): void
    {
        $this->assertSame(4.99, $this->shipping->priceFor(['cost' => '4.99', 'free_over' => null], 10.0));
        $this->assertSame(5.0, $this->shipping->priceFor(['cost' => '4.999'], 10.0), 'rounded to cents');
    }

    public function testFreeAtOrAboveThreshold(): void
    {
        $method = ['cost' => '6.00', 'free_over' => '50.00'];
        $this->assertSame(6.0, $this->shipping->priceFor($method, 49.99));
        $this->assertSame(0.0, $this->shipping->priceFor($method, 50.0));
        $this->assertSame(0.0, $this->shipping->priceFor($method, 120.0));
        $this->assertSame(6.0, $this->shipping->priceFor(['cost' => '6.00', 'free_over' => ''], 500.0), 'empty threshold = never free');
    }

    public function testCountries(): void
    {
        $method = ['countries' => 'Portugal, pt , Spain,,ES'];
        $this->assertSame(['PORTUGAL', 'PT', 'SPAIN', 'ES'], $this->shipping->countryList($method));
        $this->assertTrue($this->shipping->servesCountry($method, ' portugal '));
        $this->assertTrue($this->shipping->servesCountry($method, 'es'));
        $this->assertFalse($this->shipping->servesCountry($method, 'France'));
        $this->assertTrue($this->shipping->servesCountry(['countries' => null], 'Anywhere'), 'no list = everywhere');
    }

    public function testLabel(): void
    {
        $this->assertSame('Express (1-2 days)', $this->shipping->label(['name' => 'Express', 'description' => '1-2 days']));
        $this->assertSame('Pickup', $this->shipping->label(['name' => 'Pickup', 'description' => ' ']));
    }

    public function testValidate(): void
    {
        [$data, $errors] = $this->shipping->validate(['name' => 'Post', 'cost' => '3,5', 'free_over' => '', 'countries' => 'PT, PT, ES', 'is_active' => '1']);
        $this->assertSame([], $errors);
        $this->assertSame(3.5, $data['cost']);
        $this->assertNull($data['free_over']);
        $this->assertSame('PT, ES', $data['countries']);
        $this->assertSame(1, $data['is_active']);

        [, $errors] = $this->shipping->validate(['name' => '', 'cost' => '-1', 'free_over' => 'abc']);
        $this->assertCount(3, $errors);
    }
}
