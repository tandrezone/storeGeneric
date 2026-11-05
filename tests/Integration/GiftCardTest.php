<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\GiftCardRepository;
use App\Service\CheckoutService;
use App\Service\GiftCardService;
use App\Service\OrderService;
use RuntimeException;
use Tests\IntegrationTestCase;

final class GiftCardTest extends IntegrationTestCase
{
    private function card(float $amount, ?string $expires = null): array
    {
        return $this->get(GiftCardService::class)->issue($amount, null, $expires, null, null, 'tester');
    }

    private function balance(int $cardId): float
    {
        return (float) $this->pdo()->query("SELECT balance FROM gift_cards WHERE id = $cardId")->fetchColumn();
    }

    private function customer(): array
    {
        return ['name' => 'A B', 'email' => 'a@example.com', 'phone' => '', 'address1' => '1 St', 'address2' => '', 'city' => 'Lisbon', 'state' => '', 'postal_code' => '1000', 'country' => 'Portugal'];
    }

    /** Standard shipping at 0 so totals are easy: 2 × 10.00 = 20.00. */
    private function order(string $giftCode, int $qty = 2, string $payment = 'bank_transfer'): array
    {
        $this->pdo()->exec("UPDATE shipping_methods SET cost = 0, cost_per_kg = 0, free_over = NULL, min_weight_grams = NULL, max_weight_grams = NULL WHERE code = 'standard'");
        $ids = $this->makeProduct($this->sku('G'), 10.0, 20);
        $items = [['variant_id' => $ids['variant'], 'quantity' => $qty, 'price' => 10.0, 'product_name' => 'Test product', 'weight_grams' => 0]];

        return $this->get(CheckoutService::class)->placeOrder($this->customer(), $items, 'standard', $payment, '', $giftCode);
    }

    public function testCodesAreGeneratedUniqueAndNormalised(): void
    {
        $service = $this->get(GiftCardService::class);
        $code = $service->generateCode();
        $this->assertTrue(preg_match('/^GC-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code) === 1);
        $this->assertSame('GC-AB12CD', GiftCardService::normalize(' gc-ab12 cd '));
        $card = $this->card(25.0);
        $this->assertEquals(25.0, $card['balance']);
        $this->assertSame($card['code'], $service->usable(strtolower($card['code']))['code'], 'case-insensitive');
    }

    public function testPartialPaymentLeavesTheRestToTheNormalMethod(): void
    {
        $card = $this->card(5.0);
        $order = $this->order($card['code']);
        $row = $this->orderRow($order['id']);
        $this->assertEquals(15.0, $row['total'], '20.00 − 5.00 card');
        $this->assertEquals(5.0, $row['gift_card_amount']);
        $this->assertSame($card['code'], $row['gift_card_code']);
        $this->assertEquals(1, $row['gift_card_counted']);
        $this->assertEquals(0.0, $this->balance((int) $card['id']));
        $this->assertSame(15.0, $order['total']);
    }

    public function testCardBiggerThanTheOrderOnlyPaysWhatIsDue(): void
    {
        $card = $this->card(50.0);
        $order = $this->order($card['code']);
        $this->assertSame(0.0, $order['total']);
        $this->assertEquals(20.0, $this->orderRow($order['id'])['gift_card_amount']);
        $this->assertEquals(30.0, $this->balance((int) $card['id']));
    }

    public function testCancellingGivesTheMoneyBackExactlyOnce(): void
    {
        $card = $this->card(8.0);
        $order = $this->order($card['code']);
        $this->assertEquals(0.0, $this->balance((int) $card['id']));
        $service = $this->get(OrderService::class);

        $service->cancel($order['id']);
        $this->assertEquals(8.0, $this->balance((int) $card['id']));
        $this->assertEquals(0, $this->orderRow($order['id'])['gift_card_counted']);
        $this->get(GiftCardService::class)->release($this->orderRow($order['id']));
        $this->assertEquals(8.0, $this->balance((int) $card['id']), 'a second release adds nothing');

        $types = $this->pdo()->query('SELECT type FROM gift_card_transactions WHERE gift_card_id = ' . (int) $card['id'] . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['issue', 'redeem', 'restore'], $types);
    }

    public function testRefundingAPaidOrderRestoresTheCardPart(): void
    {
        $card = $this->card(6.0);
        $order = $this->order($card['code']);
        $service = $this->get(OrderService::class);
        $service->markPaid($order['id']);
        $this->assertEquals(0.0, $this->balance((int) $card['id']));
        $service->refund($order['id'], true);
        $this->assertEquals(6.0, $this->balance((int) $card['id']));
    }

    public function testInvalidCardsAreRefused(): void
    {
        $service = $this->get(GiftCardService::class);
        $this->assertThrows(RuntimeException::class, fn () => $service->usable('GC-NOPE-NOPE-NOPE'), 'doesn\'t exist');

        $card = $this->card(10.0);
        $this->pdo()->exec('UPDATE gift_cards SET is_active = 0 WHERE id = ' . (int) $card['id']);
        $this->assertThrows(RuntimeException::class, fn () => $service->usable($card['code']), 'not active');

        $expired = $this->card(10.0);
        $this->pdo()->exec("UPDATE gift_cards SET expires_at = NOW() - INTERVAL 1 DAY WHERE id = " . (int) $expired['id']);
        $this->assertThrows(RuntimeException::class, fn () => $service->usable($expired['code']), 'expired');

        $empty = $this->card(10.0);
        $this->pdo()->exec('UPDATE gift_cards SET balance = 0 WHERE id = ' . (int) $empty['id']);
        $this->assertThrows(RuntimeException::class, fn () => $service->usable($empty['code']), 'no balance');

        $this->assertThrows(RuntimeException::class, fn () => $this->order('GC-NOPE-NOPE-NOPE'), 'doesn\'t exist');
    }

    public function testTwoOrdersCannotSpendTheSameMoney(): void
    {
        $card = $this->card(12.0);
        $first = $this->order($card['code']); // takes 12.00 of the 20.00
        $this->assertEquals(8.0, $first['total']);
        $this->assertEquals(0.0, $this->balance((int) $card['id']));

        $before = (int) $this->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $this->assertThrows(RuntimeException::class, fn () => $this->order($card['code'], 1), 'no balance left');
        $this->assertSame($before + 0, (int) $this->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn() - 0, 'the refused order was rolled back');
        $this->assertEquals(0.0, $this->balance((int) $card['id']));
    }

    public function testIssueValidationAndAdjustments(): void
    {
        $service = $this->get(GiftCardService::class);
        $this->assertThrows(RuntimeException::class, fn () => $service->issue(0.0, null, null, null, null, null), 'above 0');
        $this->assertThrows(RuntimeException::class, fn () => $service->issue(20000.0, null, null, null, null, null), 'at most');
        $this->assertThrows(RuntimeException::class, fn () => $service->issue(5.0, 'x', null, null, null, null), '10-40');
        $this->assertThrows(RuntimeException::class, fn () => $service->issue(5.0, 'SHORT1', null, null, null, null), '10-40');
        $this->assertThrows(RuntimeException::class, fn () => $service->issue(5.0, null, '2000-01-01', null, null, null), 'future');
        $this->assertThrows(RuntimeException::class, fn () => $service->issue(5.0, null, null, 'not-an-email', null, null), 'valid email');
        $card = $this->card(10.0);
        $this->assertThrows(RuntimeException::class, fn () => $service->issue(5.0, $card['code'], null, null, null, null), 'already exists');

        $service->adjust((int) $card['id'], 5.0, 'top up', 'tester');
        $this->assertEquals(15.0, $this->balance((int) $card['id']));
        $service->adjust((int) $card['id'], -3.0, 'fix', 'tester');
        $this->assertEquals(12.0, $this->balance((int) $card['id']));
        $this->assertThrows(RuntimeException::class, fn () => $service->adjust((int) $card['id'], -50.0, '', null), 'below 0');
        $this->assertThrows(RuntimeException::class, fn () => $service->adjust((int) $card['id'], 0.0, '', null), 'other than 0');
        $this->assertEquals(12.0, $this->get(GiftCardRepository::class)->find((int) $card['id'])['balance']);
    }
}
