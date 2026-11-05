<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\SettingRepository;
use Tests\HttpTestCase;

final class CurrencyDisplayHttpTest extends HttpTestCase
{
    public function tearDown(): void
    {
        $settings = $this->get(SettingRepository::class);
        $settings->set('currency_rates', null);
        $settings->set('currency_rates_auto', null);
        parent::tearDown();
    }

    public function testSwitcherAndApproximatePricesOnTheCatalogueOnly(): void
    {
        $ids = $this->makeProduct($this->sku('C'), 10.0, 5, 'Priced thing');
        $path = parse_url($this->get_('/product/' . $ids['product'])->getHeaderLine('Location'), PHP_URL_PATH);

        $this->assertStringNotContainsString('currency-switcher', $this->html($this->get_('/')), 'nothing configured, nothing shown');

        $this->loginAdmin('owner');
        $this->post('/admin/settings', ['action' => 'save_currencies', 'currency_rates' => 'USD=1.20, GBP=0.80']);
        $home = $this->html($this->get_('/'));
        $this->assertStringContainsString('currency-switcher', $home);
        $this->assertStringContainsString('code=USD', $home);
        $this->assertStringNotContainsString('price-approx', $home, 'no currency chosen yet');

        $select = $this->get_('/currency', ['code' => 'USD', 'back' => '/']);
        $this->assertSame('/', $select->getHeaderLine('Location'));
        $this->assertStringContainsString('class="price-approx">≈', $this->html($this->get_('/')));
        $this->assertStringContainsString('≈', $this->html($this->get_($path)));
        $this->assertStringContainsString('12.00', $this->html($this->get_($path)), '10.00 × 1.20');

        $this->assertStringNotContainsString('currency-switcher', $this->html($this->get_('/checkout')));
        $this->assertStringNotContainsString('≈', $this->html($this->get_('/cart')));

        $this->get_('/currency', ['back' => '/']);
        $this->assertStringNotContainsString('price-approx', $this->html($this->get_('/')), 'switched off');
    }

    public function testBackRedirectStaysOnTheSite(): void
    {
        $this->assertSame('/', $this->get_('/currency', ['code' => 'USD', 'back' => 'https://evil.example/x'])->getHeaderLine('Location'));
        $this->assertSame('/', $this->get_('/currency', ['code' => 'USD', 'back' => '//evil.example'])->getHeaderLine('Location'));
    }

    public function testBadRatesAreRefusedInSettings(): void
    {
        $this->loginAdmin('owner');
        $response = $this->post('/admin/settings', ['action' => 'save_currencies', 'currency_rates' => 'USD=1.2, not a rate']);
        $this->assertStatus(422, $response);
        $this->assertSame([], $this->get(\App\Service\CurrencyService::class)->rates());
    }
}
