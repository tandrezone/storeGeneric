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

    public function testPerKgSurchargeAddsToTheFlatCost(): void
    {
        $method = ['cost' => '4.00', 'cost_per_kg' => '2.50', 'free_over' => null];
        $this->assertSame(4.0, $this->shipping->priceFor($method, 10.0), 'no weight, no surcharge');
        $this->assertSame(6.5, $this->shipping->priceFor($method, 10.0, 1000));
        $this->assertSame(5.25, $this->shipping->priceFor($method, 10.0, 500));
        $this->assertSame(0.0, $this->shipping->priceFor(array_replace($method, ['free_over' => '5']), 10.0, 3000), 'free over wins');
    }

    public function testWeightLimits(): void
    {
        $method = ['min_weight_grams' => 500, 'max_weight_grams' => 2000];
        $this->assertFalse($this->shipping->servesWeight($method, 499));
        $this->assertTrue($this->shipping->servesWeight($method, 500));
        $this->assertTrue($this->shipping->servesWeight($method, 2000));
        $this->assertFalse($this->shipping->servesWeight($method, 2001));
        $this->assertTrue($this->shipping->servesWeight(['min_weight_grams' => null, 'max_weight_grams' => null], 99999));
        $this->assertTrue($this->shipping->servesWeight([], 5));
    }

    public function testValidateWeightFields(): void
    {
        [$data, $errors] = $this->shipping->validate(['name' => 'Heavy', 'cost' => '5', 'cost_per_kg' => '1,5', 'min_weight_grams' => '1000', 'max_weight_grams' => '30000']);
        $this->assertSame([], $errors);
        $this->assertSame(1.5, $data['cost_per_kg']);
        $this->assertSame(1000, $data['min_weight_grams']);
        $this->assertSame(30000, $data['max_weight_grams']);

        [, $errors] = $this->shipping->validate(['name' => 'X', 'cost' => '5', 'min_weight_grams' => '3000', 'max_weight_grams' => '1000']);
        $this->assertCount(1, $errors);
        [, $errors] = $this->shipping->validate(['name' => 'X', 'cost' => '5', 'min_weight_grams' => 'abc']);
        $this->assertCount(1, $errors);
        [$data] = $this->shipping->validate(['name' => 'X', 'cost' => '5']);
        $this->assertNull($data['min_weight_grams']);
        $this->assertSame(0.0, $data['cost_per_kg']);
    }
}
