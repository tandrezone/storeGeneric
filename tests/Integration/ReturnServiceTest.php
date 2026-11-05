<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\OrderRepository;
use App\Repository\ReturnRepository;
use App\Repository\SettingRepository;
use App\Service\OrderService;
use App\Service\ReturnService;
use RuntimeException;
use Tests\IntegrationTestCase;

final class ReturnServiceTest extends IntegrationTestCase
{
    private function shippedOrder(int $qty = 3): array
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 10, 'Returnable');
        $order = $this->placeOrder($ids['variant'], $qty, 10.0);
        $service = $this->get(OrderService::class);
        $service->markPaid($order['id']);
        $service->ship($order['id'], 'TRK1', 'DHL');

        return $this->get(OrderRepository::class)->find($order['id']);
    }

    public function tearDown(): void
    {
        $this->get(SettingRepository::class)->set('return_window_days', null);
        parent::tearDown();
    }

    public function testNotReturnableUntilShipped(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 10);
        $order = $this->get(OrderRepository::class)->find($this->placeOrder($ids['variant'], 1, 10.0)['id']);
        $this->assertSame([], $this->get(ReturnService::class)->returnable($order));
    }

    public function testRequestSplitsQuantitiesAcrossRequests(): void
    {
        $returns = $this->get(ReturnService::class);
        $order = $this->shippedOrder(3);
        $itemId = (int) $order['items'][0]['id'];

        $this->assertSame([$itemId => 3], $returns->returnable($order));
        $returns->request($order, [$itemId => 2], 'damaged', 'Box was crushed');
        $this->assertSame([$itemId => 1], $returns->returnable($order));
        $this->assertThrows(RuntimeException::class, fn () => $returns->request($order, [$itemId => 2], 'damaged', ''), 'more items');
        $returns->request($order, [$itemId => 1], 'changed_mind', '');
        $this->assertSame([], $returns->returnable($order));
        $this->assertThrows(RuntimeException::class, fn () => $returns->request($order, [$itemId => 1], 'damaged', ''), 'can\'t be returned');
    }

    public function testRejectedRequestGivesTheItemsBack(): void
    {
        $returns = $this->get(ReturnService::class);
        $order = $this->shippedOrder(1);
        $itemId = (int) $order['items'][0]['id'];
        $id = $returns->request($order, [$itemId => 1], 'other', '');
        $this->assertSame([], $returns->returnable($order));

        $returns->setStatus($id, 'rejected', 'Used item');
        $this->assertSame([$itemId => 1], $returns->returnable($order));
        $this->assertSame('Used item', $this->get(ReturnRepository::class)->find($id)['admin_response']);
    }

    public function testValidation(): void
    {
        $returns = $this->get(ReturnService::class);
        $order = $this->shippedOrder(1);
        $itemId = (int) $order['items'][0]['id'];
        $this->assertThrows(RuntimeException::class, fn () => $returns->request($order, [$itemId => 1], 'bogus', ''), 'reason');
        $this->assertThrows(RuntimeException::class, fn () => $returns->request($order, [$itemId => 0], 'damaged', ''), 'at least one');
        $this->assertThrows(RuntimeException::class, fn () => $returns->request($order, [999999 => 1], 'damaged', ''), 'more items');
    }

    public function testStatusFlowIsEnforced(): void
    {
        $returns = $this->get(ReturnService::class);
        $order = $this->shippedOrder(1);
        $id = $returns->request($order, [(int) $order['items'][0]['id'] => 1], 'damaged', '');

        $this->assertThrows(RuntimeException::class, fn () => $returns->setStatus($id, 'received', ''), 'isn\'t possible');
        $returns->setStatus($id, 'approved', '');
        $returns->setStatus($id, 'received', '');
        $returns->setStatus($id, 'refunded', '');
        $this->assertThrows(RuntimeException::class, fn () => $returns->setStatus($id, 'approved', ''), 'isn\'t possible');
        $this->assertSame([], $returns->nextStatuses('refunded'));
    }

    public function testWindowAndSwitch(): void
    {
        $settings = $this->get(SettingRepository::class);
        $returns = $this->get(ReturnService::class);
        $order = $this->shippedOrder(1);

        $this->assertSame(14, $returns->windowDays());
        $this->assertTrue($returns->returnable($order) !== []);
        $this->assertSame([], $returns->returnable($order, new \DateTimeImmutable('+15 days')), 'window over');
        $this->assertTrue($returns->returnable($order, new \DateTimeImmutable('+13 days')) !== []);

        $settings->set('return_window_days', '0');
        $this->assertSame([], $returns->returnable($order), 'returns switched off');
    }
}
