<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\ReviewRepository;
use App\Repository\SettingRepository;
use App\Service\OrderService;
use Tests\HttpTestCase;

final class ReviewsHttpTest extends HttpTestCase
{
    public function tearDown(): void
    {
        $this->get(SettingRepository::class)->set('reviews_auto_approve', null);
        parent::tearDown();
    }

    private function productPath(int $id): string
    {
        return parse_url($this->get_('/product/' . $id)->getHeaderLine('Location'), PHP_URL_PATH);
    }

    public function testReviewWaitsForApprovalThenShowsWithRatingMarkup(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5, 'Reviewed item');
        $path = $this->productPath($ids['product']);

        $this->assertStringContainsString('Sign in to write a review', $this->html($this->get_($path)));
        $this->assertRedirectsTo('/account/login', $this->post('/product/' . $ids['product'] . '/review', ['rating' => '5']));

        $customer = $this->loginCustomer();
        $this->assertStringContainsString('Write a review', $this->html($this->get_($path)));

        $this->post('/product/' . $ids['product'] . '/review', ['rating' => '4', 'title' => 'Nice', 'body' => 'Works well']);
        $page = $this->html($this->get_($path));
        $this->assertStringNotContainsString('Works well', $page, 'pending reviews are hidden');
        $this->assertStringContainsString('waiting to be checked', $page);

        $this->loginAdmin('staff');
        $id = (int) $this->pdo()->query('SELECT MAX(id) FROM product_reviews')->fetchColumn();
        $this->post('/admin/reviews', ['id' => $id, 'action' => 'approve']);

        $page = $this->html($this->get_($path));
        $this->assertStringContainsString('Works well', $page);
        $this->assertStringContainsString('"aggregateRating"', $page);
        $this->assertStringContainsString('"ratingValue":"4.0"', $page);
        $this->assertStringContainsString('Test C.', $page);
    }

    public function testOneReviewPerProductAndRatingIsValidated(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $this->loginCustomer();
        $url = '/product/' . $ids['product'] . '/review';
        $this->post($url, ['rating' => '9', 'body' => 'x']);
        $this->post($url, ['rating' => '3', 'body' => '']);
        $this->assertSame(0, $this->get(ReviewRepository::class)->countForAdmin(null) - (int) $this->pdo()->query('SELECT COUNT(*) FROM product_reviews WHERE product_id <> ' . (int) $ids['product'])->fetchColumn());

        $this->post($url, ['rating' => '5', 'body' => 'First']);
        $this->post($url, ['rating' => '1', 'body' => 'Second']);
        $this->assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM product_reviews WHERE product_id = ' . (int) $ids['product'])->fetchColumn());
    }

    public function testVerifiedPurchaseAndAutoApprove(): void
    {
        $this->get(SettingRepository::class)->set('reviews_auto_approve', '1');
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $customer = $this->loginCustomer();
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $this->pdo()->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$customer['id'], $order['id']]);
        $this->assertFalse($this->get(ReviewRepository::class)->hasPurchased($customer['id'], $ids['product']), 'unpaid order is no proof');
        $this->get(OrderService::class)->markPaid($order['id']);

        $this->post('/product/' . $ids['product'] . '/review', ['rating' => '5', 'body' => 'Bought it']);
        $row = $this->pdo()->query('SELECT status, verified FROM product_reviews WHERE product_id = ' . (int) $ids['product'])->fetch();
        $this->assertSame('approved', $row['status']);
        $this->assertEquals(1, $row['verified']);
        $this->assertStringContainsString('Verified purchase', $this->html($this->get_($this->productPath($ids['product']))));
    }

    public function testDeletingTheAccountKeepsTheReviewAnonymously(): void
    {
        $this->get(SettingRepository::class)->set('reviews_auto_approve', '1');
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $customer = $this->loginCustomer('correct horse battery');
        $this->post('/product/' . $ids['product'] . '/review', ['rating' => '5', 'body' => 'Still here']);
        $this->post('/account/profile', ['action' => 'delete', 'confirm' => '1', 'current_password' => 'correct horse battery']);

        $row = $this->pdo()->query('SELECT customer_id, author, body FROM product_reviews WHERE product_id = ' . (int) $ids['product'])->fetch();
        $this->assertNull($row['customer_id']);
        $this->assertSame('', $row['author']);
        $this->assertSame('Still here', $row['body']);
    }

    public function testStarsAverageAndDistribution(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $repo = $this->get(ReviewRepository::class);
        $this->assertSame(0, $repo->summary($ids['product'])['count']);
        foreach ([5, 4, 4] as $i => $rating) {
            $c = $this->http()->get(\App\Repository\CustomerRepository::class)->create("r$i" . bin2hex(random_bytes(2)) . '@example.com', 'A B', 'x');
            $repo->create($ids['product'], $c, 'A B.', $rating, null, 'ok', 'approved', false);
        }
        $repo->create($ids['product'], null, 'Hidden', 1, null, 'bad', 'pending', false);
        $summary = $repo->summary($ids['product']);
        $this->assertSame(3, $summary['count']);
        $this->assertEquals(4.3, $summary['average']);
        $this->assertSame(2, $summary['distribution'][4]);
    }
}
