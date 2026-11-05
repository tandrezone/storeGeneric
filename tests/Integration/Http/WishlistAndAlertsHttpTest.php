<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\StockAlertRepository;
use App\Repository\WishlistRepository;
use App\Service\MailQueue;
use App\Service\StockAlertService;
use Tests\HttpTestCase;

final class WishlistAndAlertsHttpTest extends HttpTestCase
{
    private function path(int $productId): string
    {
        return parse_url($this->get_('/product/' . $productId)->getHeaderLine('Location'), PHP_URL_PATH);
    }

    public function testWishlistToggleAndPage(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5, 'Wished thing');
        $path = $this->path($ids['product']);

        $this->assertRedirectsTo('/account/login', $this->post('/account/wishlist', ['product_id' => $ids['product'], 'back' => $path]));
        $customer = $this->loginCustomer();

        $save = $this->post('/account/wishlist', ['product_id' => $ids['product'], 'back' => $path]);
        $this->assertSame($path, $save->getHeaderLine('Location'));
        $this->assertTrue($this->get(WishlistRepository::class)->has($customer['id'], $ids['product']));
        $this->assertStringContainsString('Saved', $this->html($this->get_($path)));
        $this->assertStringContainsString('Wished thing', $this->html($this->get_('/account/wishlist')));

        $this->post('/account/wishlist', ['product_id' => $ids['product'], 'back' => $path]); // toggles off
        $this->assertFalse($this->get(WishlistRepository::class)->has($customer['id'], $ids['product']));
    }

    public function testWishlistRefusesOffsiteRedirects(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $this->loginCustomer();
        $r = $this->post('/account/wishlist', ['product_id' => $ids['product'], 'back' => 'https://evil.example/x']);
        $this->assertSame('/account/wishlist', $r->getHeaderLine('Location'));
        $r = $this->post('/account/wishlist', ['product_id' => $ids['product'], 'back' => '//evil.example/x']);
        $this->assertSame('/account/wishlist', $r->getHeaderLine('Location'));
    }

    public function testDeletingAnAccountEmptiesItsWishlist(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $customer = $this->loginCustomer('correct horse battery');
        $this->post('/account/wishlist', ['product_id' => $ids['product'], 'action' => 'add']);
        $this->post('/account/profile', ['action' => 'delete', 'confirm' => '1', 'current_password' => 'correct horse battery']);
        $this->assertSame(0, $this->get(WishlistRepository::class)->count($customer['id']));
    }

    public function testBackInStockFlow(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 0, 'Sold out thing'); // no stock
        $path = $this->path($ids['product']);
        $this->assertStringContainsString('Email me when it is back', $this->html($this->get_($path)));

        $this->post('/product/' . $ids['product'] . '/notify', ['email' => 'Waiting@Example.com', 'website' => '']);
        $this->post('/product/' . $ids['product'] . '/notify', ['email' => 'bad']);
        $this->post('/product/' . $ids['product'] . '/notify', ['email' => 'bot@example.com', 'website' => 'http://spam']);
        $this->assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM stock_alerts WHERE product_id = ' . (int) $ids['product'])->fetchColumn());

        $service = $this->get(StockAlertService::class);
        $before = (int) $this->pdo()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $this->assertSame(0, $service->notifyDue(), 'still out of stock');

        $this->pdo()->exec('UPDATE product_variants SET stock = 4 WHERE id = ' . (int) $ids['variant']);
        $this->assertSame(1, $service->notifyDue());
        $this->assertSame(0, $service->notifyDue(), 'only once');
        $mail = $this->pdo()->query('SELECT to_email, subject FROM mail_queue ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('waiting@example.com', $mail['to_email']);
        $this->assertStringContainsString('Sold out thing', $mail['subject']);
        $this->assertSame($before + 1, (int) $this->pdo()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());
    }

    public function testCancelLink(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 0);
        $token = $this->get(StockAlertRepository::class)->subscribe($ids['product'], 'x@example.com', 'en');
        $this->get_('/stock-alert/cancel', ['token' => $token]);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM stock_alerts WHERE token = ' . $this->pdo()->quote($token))->fetchColumn());
        $this->get_('/stock-alert/cancel', ['token' => 'nonsense']); // harmless
    }
}
