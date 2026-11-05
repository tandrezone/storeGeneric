<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\OrderNoteRepository;
use Tests\IntegrationTestCase;

final class OrderNotesTest extends IntegrationTestCase
{
    public function testNotesAreStoredPerOrderAndDeletedWithTheirOrderOnly(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $a = $this->placeOrder($ids['variant'], 1, 10.0);
        $b = $this->placeOrder($ids['variant'], 1, 10.0);
        $notes = $this->get(OrderNoteRepository::class);

        $first = $notes->add($a['id'], 'alice', '  Call the customer  ');
        $notes->add($b['id'], 'bob', 'Other order');

        $list = $notes->forOrder($a['id']);
        $this->assertCount(1, $list);
        $this->assertSame('Call the customer', $list[0]['body']);
        $this->assertSame('alice', $list[0]['author']);
        $this->assertSame([$a['id'] => 1, $b['id'] => 1], $notes->countsFor([$a['id'], $b['id']]));

        $notes->delete($first, $b['id']); // wrong order: nothing happens
        $this->assertCount(1, $notes->forOrder($a['id']));
        $notes->delete($first, $a['id']);
        $this->assertCount(0, $notes->forOrder($a['id']));
    }

    public function testLongNotesAreCut(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $notes = $this->get(OrderNoteRepository::class);
        $notes->add($order['id'], null, str_repeat('x', 5000));
        $this->assertSame(OrderNoteRepository::MAX_LENGTH, mb_strlen($notes->forOrder($order['id'])[0]['body']));
    }
}
