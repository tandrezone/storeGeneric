<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Infrastructure\HttpClient;
use App\Repository\SettingRepository;
use App\Service\OrderService;
use Tests\FakeVies;
use Tests\HttpTestCase;

final class ReverseChargeHttpTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        putenv('PAYMENT_COD_ENABLED=true');
        $settings = $this->get(SettingRepository::class);
        foreach (['tax_enabled' => '1', 'tax_rate' => '23', 'tax_prices_include' => '1', 'tax_shipping' => '1', 'company_country' => 'PT'] as $k => $v) {
            $settings->set($k, $v);
        }
        $this->pdo()->exec('DELETE FROM vat_checks');
        $this->pdo()->exec("DELETE FROM orders WHERE email = 'buyer@acme.test'");
        $this->pdo()->exec("UPDATE shipping_methods SET cost = 0, cost_per_kg = 0, free_over = NULL, min_weight_grams = NULL, max_weight_grams = NULL, countries = NULL WHERE code = 'standard'");
    }

    public function tearDown(): void
    {
        putenv('PAYMENT_COD_ENABLED');
        $settings = $this->get(SettingRepository::class);
        foreach (['tax_enabled', 'tax_rate', 'tax_prices_include', 'tax_shipping', 'company_country'] as $k) {
            $settings->set($k, null);
        }
        parent::tearDown();
    }

    private function cart(): void
    {
        $ids = $this->makeProduct($this->sku('V'), 61.5, 20, 'B2B thing'); // 2 × 61.50 = 123.00 incl. 23% VAT
        $this->pdo()->exec('DELETE FROM carts');
        $this->request('POST', '/api/cart', ['action' => 'add', 'variant_id' => $ids['variant'], 'quantity' => 2]);
    }

    private function vies(array|\Throwable ...$answers): void
    {
        $this->overrides[HttpClient::class] = new FakeVies($answers);
    }

    private function valid(): array
    {
        return ['status' => 200, 'body' => ['isValid' => true, 'name' => 'ACME SL', 'address' => 'MADRID']];
    }

    /** @return array<string, string> */
    private function form(array $extra = []): array
    {
        return $extra + ['name' => 'Acme', 'email' => 'buyer@acme.test', 'phone' => '', 'address1' => 'Calle 1', 'address2' => '', 'city' => 'Madrid', 'state' => '', 'postal_code' => '28001', 'country' => 'Spain', 'shipping_method' => 'standard', 'payment_method' => 'cod'];
    }

    public function testQuoteRemovesTheVatForAValidNumberInAnotherEuCountry(): void
    {
        $this->cart();
        $this->vies($this->valid());
        $quote = json_decode($this->html($this->get_('/api/checkout/quote', ['country' => 'Spain', 'shipping_method' => 'standard', 'vat_id' => 'ES B12345678'])), true);
        $this->assertTrue($quote['reverse_charge']);
        $this->assertEquals(100.0, $quote['total'], '123.00 / 1.23');
        $this->assertEquals(0.0, $quote['tax_amount']);

        $plain = json_decode($this->html($this->get_('/api/checkout/quote', ['country' => 'Spain', 'shipping_method' => 'standard'])), true);
        $this->assertFalse($plain['reverse_charge']);
        $this->assertEquals(123.0, $plain['total']);
    }

    public function testOrderIsPlacedWithoutVatAndRecordsTheNumber(): void
    {
        $this->cart();
        $this->vies($this->valid());
        $placed = $this->post('/checkout', $this->form(['vat_id' => 'esb12345678']));
        $this->assertTrue(in_array($placed->getStatusCode(), [302, 303], true), (string) $placed->getStatusCode());

        $order = $this->pdo()->query("SELECT * FROM orders WHERE email = 'buyer@acme.test' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertEquals(100.0, $order['total']);
        $this->assertEquals(0.0, $order['tax_amount']);
        $this->assertSame('ESB12345678', $order['vat_id']);
        $this->assertEquals(1, $order['vat_reverse_charge']);

        $this->loginAdmin('manager');
        $this->get(OrderService::class)->markPaid((int) $order['id']);
        $page = $this->html($this->get_('/admin/orders/' . $order['id']));
        $this->assertStringContainsString('VAT reverse charge', $page);
        $this->assertStringContainsString('ESB12345678', $page);
        $this->assertTrue(str_starts_with($this->html($this->get_('/admin/orders/' . $order['id'] . '/invoice')), '%PDF'));
    }

    public function testInvalidNumberStopsTheOrderAndUnavailableAsksToRetry(): void
    {
        $this->cart();
        $this->vies(['status' => 200, 'body' => ['isValid' => false, 'userError' => 'INVALID']]);
        $bad = $this->post('/checkout', $this->form(['vat_id' => 'ES B99999999']));
        $this->assertStatus(422, $bad);
        $this->assertStringContainsString('not valid in the EU VAT register', $this->html($bad));
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM orders WHERE email = 'buyer@acme.test'")->fetchColumn());

        $this->vies(new \RuntimeException('down'));
        $down = $this->post('/checkout', $this->form(['vat_id' => 'ES B88888888']));
        $this->assertStatus(422, $down);
        $this->assertStringContainsString('try again in a moment', $this->html($down));
    }

    public function testNumberFromTheStoresOwnCountryPaysVatAsUsual(): void
    {
        $this->cart();
        $this->vies($this->valid());
        $this->post('/checkout', $this->form(['vat_id' => 'PT123456789', 'country' => 'Portugal', 'city' => 'Lisboa']));
        $order = $this->pdo()->query("SELECT total, vat_reverse_charge, vat_id FROM orders WHERE email = 'buyer@acme.test' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertEquals(123.0, $order['total']);
        $this->assertEquals(0, $order['vat_reverse_charge']);
        $this->assertSame('PT123456789', $order['vat_id'], 'still recorded for the invoice');
    }

    public function testFieldOnlyShowsWhenTheFeatureIsOn(): void
    {
        $this->cart();
        $this->assertStringContainsString('name="vat_id"', $this->html($this->get_('/checkout')));
        $this->get(SettingRepository::class)->set('company_country', null);
        $this->assertStringNotContainsString('name="vat_id"', $this->html($this->get_('/checkout')));
    }
}
