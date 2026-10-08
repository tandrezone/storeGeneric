<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Infrastructure\Database;
use App\Repository\CouponRepository;
use App\Repository\OrderRepository;
use App\Repository\SettingRepository;
use App\Repository\ShippingMethodRepository;
use App\Repository\VariantRepository;
use App\Service\CheckoutService;
use App\Service\CouponService;
use App\Service\ShippingService;
use App\Service\StoreSettings;
use App\Service\TaxCalculator;
use App\Service\TaxSettings;
use App\Support\Config;
use App\Support\Paths;
use Tests\TestCase;

/** VAT totals and coupon discounts with settings given in memory (no database). */
final class TaxAndCouponTest extends TestCase
{
    public function setUp(): void
    {
        if (!class_exists(TaxCalculator::class) || !class_exists(CouponService::class)) {
            $this->skip('TaxCalculator / CouponService are not in this codebase');
        }
    }

    public function testVatDisabled(): void
    {
        $totals = $this->calculator(['tax_enabled' => '0'])->totals([10.0, 5.5], 0.0, 4.0, 0.0, 'PT');
        $this->assertSame(15.5, $totals['subtotal']);
        $this->assertSame(0.0, $totals['tax_amount']);
        $this->assertSame(19.5, $totals['total']);
    }

    public function testPricesIncludeVat(): void
    {
        $totals = $this->calculator(['tax_enabled' => '1', 'tax_rate' => '23', 'tax_prices_include' => '1', 'tax_shipping' => '0'])
            ->totals([123.0], 0.0, 5.0, 0.0, 'Portugal');
        $this->assertSame(23.0, $totals['tax_amount'], 'extracted from the price');
        $this->assertSame(128.0, $totals['total'], 'total unchanged by VAT');
        $this->assertTrue($totals['prices_include_tax']);
    }

    public function testVatAddedOnTopWithDiscountAndShipping(): void
    {
        $totals = $this->calculator(['tax_enabled' => '1', 'tax_rate' => '20', 'tax_prices_include' => '0', 'tax_shipping' => '1'])
            ->totals([60.0, 40.0], 10.0, 10.0, 0.0, 'FR');
        // base (100 - 10) + 10 shipping = 100; 20% = 20
        $this->assertSame(20.0, $totals['tax_amount']);
        $this->assertSame(2.0, $totals['shipping_tax']);
        $this->assertSame(120.0, $totals['total']);
        $this->assertSame(10.0, $totals['discount']);
        $this->assertEquals(18.0, array_sum($totals['item_taxes']), 'line taxes add up to the items VAT');
    }

    public function testCountryRateOverridesDefault(): void
    {
        $calc = $this->calculator(['tax_enabled' => '1', 'tax_rate' => '20', 'tax_prices_include' => '0', 'tax_shipping' => '0', 'tax_country_rates' => 'PT=23; ES: 21%']);
        $this->assertSame(23.0, $calc->totals([100.0], 0.0, 0.0, 0.0, ' pt ')['tax_amount']);
        $this->assertSame(21.0, $calc->totals([100.0], 0.0, 0.0, 0.0, 'es')['tax_amount']);
        $this->assertSame(20.0, $calc->totals([100.0], 0.0, 0.0, 0.0, 'DE')['tax_amount']);
    }

    public function testDiscountsAreCapped(): void
    {
        $totals = $this->calculator(['tax_enabled' => '0'])->totals([20.0], 50.0, 5.0, 9.0, 'PT');
        $this->assertSame(25.0, $totals['discount'], 'items discount capped at subtotal, shipping discount at shipping');
        $this->assertSame(0.0, $totals['total']);
    }

    public function testCouponDiscounts(): void
    {
        $db = new Database(Config::fromArray([]));
        $coupons = new CouponService(new CouponRepository($db), new OrderRepository($db), $this->store([]), $this->translator());

        $this->assertSame(['items' => 8.0, 'shipping' => 0.0], $coupons->discount(['type' => 'percent', 'value' => '10'], 80.0, 5.0));
        $this->assertSame(['items' => 80.0, 'shipping' => 0.0], $coupons->discount(['type' => 'percent', 'value' => '150'], 80.0, 5.0));
        $this->assertSame(['items' => 30.0, 'shipping' => 0.0], $coupons->discount(['type' => 'fixed', 'value' => '50'], 30.0, 5.0));
        $this->assertSame(['items' => 0.0, 'shipping' => 5.0], $coupons->discount(['type' => 'free_shipping', 'value' => '0'], 30.0, 5.0));
        $this->assertSame('SUMMER-10', CouponService::normalize('  summer -10 '));
        $this->assertSame('10% off', $coupons->describe(['type' => 'percent', 'value' => '10.00']));
    }

    public function testFreeShippingThresholdUsesDiscountedSubtotal(): void
    {
        $db = new Database(Config::fromArray([]));
        $coupons = new CouponService(new CouponRepository($db), new OrderRepository($db), $this->store([]), $this->translator());
        $checkout = new CheckoutService(
            $db,
            new VariantRepository($db),
            new OrderRepository($db),
            new ShippingService(new ShippingMethodRepository($db), $this->translator()),
            $coupons,
            $this->calculator(['tax_enabled' => '0']),
            $this->translator(),
        );
        $method = ['cost' => '6.00', 'free_over' => '50.00'];

        $this->assertSame(0.0, $checkout->price([55.0], $method, null, 'PT')['shipping'], 'over the threshold without a code');
        $totals = $checkout->price([55.0], $method, ['type' => 'percent', 'value' => '20'], 'PT');
        $this->assertSame(6.0, $totals['shipping'], '55 − 20% = 44 is under the threshold');
        $this->assertSame(55.0 - 11.0 + 6.0, $totals['total']);
        $this->assertSame(0.0, $checkout->price([60.0], $method, ['type' => 'fixed', 'value' => '10'], 'PT')['shipping'], 'exactly at the threshold after the discount');
        $this->assertSame(6.0, $checkout->shippingFor($method, 55.0, ['type' => 'fixed', 'value' => '5.01']));

        $free = $checkout->price([30.0], $method, ['type' => 'free_shipping', 'value' => '0'], 'PT');
        $this->assertSame(6.0, $free['shipping']);
        $this->assertSame(6.0, $free['discount'], 'a free-shipping code takes the shipping off');
        $this->assertSame(30.0, $free['total']);
    }

    private function translator(): \App\I18n\Translator
    {
        return new \App\I18n\Translator(dirname(__DIR__, 3) . '/translations');
    }

    /** @param array<string, string> $settings */
    private function calculator(array $settings): TaxCalculator
    {
        return new TaxCalculator(new TaxSettings($this->settings($settings), new \App\I18n\Translator(dirname(__DIR__, 3) . '/translations')), $this->store($settings));
    }

    /** @param array<string, string> $settings */
    private function store(array $settings): StoreSettings
    {
        $config = Config::fromArray(['STORE_CURRENCY' => 'EUR']);

        return new StoreSettings($this->settings($settings), $config, new Paths(sys_get_temp_dir()));
    }

    /** A SettingRepository answering from memory (its cache is pre-filled). @param array<string, string> $values */
    private function settings(array $values): SettingRepository
    {
        $repository = new SettingRepository(new Database(Config::fromArray([])));
        (new \ReflectionProperty(SettingRepository::class, 'cache'))->setValue($repository, $values);

        return $repository;
    }
}
