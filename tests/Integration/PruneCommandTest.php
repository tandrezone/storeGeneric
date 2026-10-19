<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Command\PruneCommand;
use App\Console\Input;
use App\Console\Output;
use Tests\IntegrationTestCase;

final class PruneCommandTest extends IntegrationTestCase
{
    /** @param list<string> $argv */
    private function prune(array $argv): string
    {
        $stream = fopen('php://memory', 'w+');
        $status = $this->get(PruneCommand::class)->run(Input::fromArgv($argv), new Output($stream, $stream));
        rewind($stream);
        $text = (string) stream_get_contents($stream);
        $this->assertSame(0, $status, $text);

        return $text;
    }

    private function count(string $table, string $where): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    }

    /** @return array{old_view: int, new_view: int, old_audit: int, new_audit: int, old_cart: int, new_cart: int, old_attempt: int, new_attempt: int, old_token: int, new_token: int} */
    private function seed(): array
    {
        $pdo = $this->pdo();
        $id = static function () use ($pdo): int {
            return (int) $pdo->lastInsertId();
        };
        $tag = bin2hex(random_bytes(4));
        $ids = [];

        foreach (['old' => 200, 'new' => 1] as $name => $age) {
            $pdo->exec("INSERT INTO page_views (session_id, event_type, path, created_at) VALUES ('prune-{$tag}', 'visit', '/', NOW() - INTERVAL {$age} DAY)");
            $ids["{$name}_view"] = $id();
        }
        foreach (['old' => 400, 'new' => 1] as $name => $age) {
            $pdo->exec("INSERT INTO admin_audit_log (action, summary, created_at) VALUES ('prune.test', 'x', NOW() - INTERVAL {$age} DAY)");
            $ids["{$name}_audit"] = $id();
        }
        $variant = $this->makeProduct($this->sku('P'))['variant'];
        foreach (['old' => 90, 'new' => 1] as $name => $age) {
            $pdo->exec("INSERT INTO carts (session_id, updated_at) VALUES ('prune-{$name}-{$tag}', NOW() - INTERVAL {$age} DAY)");
            $ids["{$name}_cart"] = $id();
            $pdo->exec("INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES ({$ids["{$name}_cart"]}, {$variant}, 1)");
        }
        foreach (['old' => 30, 'new' => 0] as $name => $age) {
            $pdo->exec("INSERT INTO login_attempts (ip_address, username, created_at) VALUES ('203.0.113.9', 'prune-{$tag}', NOW() - INTERVAL {$age} DAY)");
            $ids["{$name}_attempt"] = $id();
        }
        $pdo->exec("INSERT INTO customers (email, name, password_hash) VALUES ('prune-{$tag}@example.com', 'Prune', 'x')");
        $customer = $id();
        foreach (['old' => '-1 DAY', 'new' => '+1 DAY'] as $name => $offset) {
            $hash = hash('sha256', $name . $tag);
            $sign = str_starts_with($offset, '-') ? '-' : '+';
            $pdo->exec("INSERT INTO customer_tokens (customer_id, purpose, token_hash, email, expires_at) VALUES ({$customer}, 'reset', '{$hash}', 'x@example.com', NOW() {$sign} INTERVAL 1 DAY)");
            $ids["{$name}_token"] = $id();
        }

        return $ids;
    }

    public function testDryRunChangesNothing(): void
    {
        $ids = $this->seed();

        $text = $this->prune(['--dry-run']);

        $this->assertStringContainsString('Would delete', $text);
        $this->assertSame(1, $this->count('page_views', 'id = ' . $ids['old_view']));
        $this->assertSame(1, $this->count('admin_audit_log', 'id = ' . $ids['old_audit']));
        $this->assertSame(1, $this->count('carts', 'id = ' . $ids['old_cart']));
        $this->assertSame(1, $this->count('login_attempts', 'id = ' . $ids['old_attempt']));
        $this->assertSame(1, $this->count('customer_tokens', 'id = ' . $ids['old_token']));
    }

    public function testDeletesOnlyOldRows(): void
    {
        $ids = $this->seed();

        $this->prune([]);

        $this->assertSame(0, $this->count('page_views', 'id = ' . $ids['old_view']));
        $this->assertSame(1, $this->count('page_views', 'id = ' . $ids['new_view']));
        $this->assertSame(0, $this->count('admin_audit_log', 'id = ' . $ids['old_audit']));
        $this->assertSame(1, $this->count('admin_audit_log', 'id = ' . $ids['new_audit']));
        $this->assertSame(0, $this->count('carts', 'id = ' . $ids['old_cart']));
        $this->assertSame(0, $this->count('cart_items', 'cart_id = ' . $ids['old_cart']));
        $this->assertSame(1, $this->count('carts', 'id = ' . $ids['new_cart']));
        $this->assertSame(1, $this->count('cart_items', 'cart_id = ' . $ids['new_cart']));
        $this->assertSame(0, $this->count('login_attempts', 'id = ' . $ids['old_attempt']));
        $this->assertSame(1, $this->count('login_attempts', 'id = ' . $ids['new_attempt']));
        $this->assertSame(0, $this->count('customer_tokens', 'id = ' . $ids['old_token']));
        $this->assertSame(1, $this->count('customer_tokens', 'id = ' . $ids['new_token']));
    }

    public function testDurationsAreOptions(): void
    {
        $ids = $this->seed();

        // A 500-day audit window keeps the 400-day-old entry; 100 days drops the 200-day-old page view only when asked.
        $this->prune(['--audit-days=500', '--page-views-days=100']);

        $this->assertSame(1, $this->count('admin_audit_log', 'id = ' . $ids['old_audit']));
        $this->assertSame(0, $this->count('page_views', 'id = ' . $ids['old_view']));
    }

    public function testRejectsBadDurations(): void
    {
        $stream = fopen('php://memory', 'w+');
        $command = $this->get(PruneCommand::class);

        $this->assertSame(1, $command->run(Input::fromArgv(['--carts-days=0']), new Output($stream, $stream)));
        $this->assertSame(1, $command->run(Input::fromArgv(['--audit-days=abc']), new Output($stream, $stream)));
    }
}
