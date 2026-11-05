<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database;
use App\Infrastructure\Mailer;
use App\Service\MailQueue;
use App\Support\Config;
use App\Support\Paths;
use Psr\Log\NullLogger;
use Tests\IntegrationTestCase;

final class MailQueueTest extends IntegrationTestCase
{
    private function queue(string $transport, string $host = ''): MailQueue
    {
        $paths = $this->get(Paths::class);
        $config = Config::fromArray(['MAIL_TRANSPORT' => $transport, 'MAIL_HOST' => $host, 'STORE_EMAIL' => 'store@example.com']);

        return new MailQueue($this->get(Database::class), new Mailer($config, $paths), new NullLogger());
    }

    private function count(string $where = '1'): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM mail_queue WHERE $where")->fetchColumn();
    }

    public function testSuccessfulDeliveryIsNotQueued(): void
    {
        $before = $this->count();
        $this->assertTrue($this->queue('log')->deliver('a@example.com', 'Hi', '<p>Hi</p>', 'Hi'));
        $this->assertSame($before, $this->count());
    }

    public function testFailedDeliveryIsKeptAndRetriedWithBackoffThenGivenUp(): void
    {
        $this->pdo()->exec('DELETE FROM mail_queue');
        $broken = $this->queue('smtp'); // MAIL_HOST missing → every send throws

        $this->assertTrue($broken->deliver('a@example.com', 'Hi', '<p>Hi</p>', 'Hi'), 'queued counts as handled');
        $this->assertSame(1, $this->count('attempts = 1 AND sent_at IS NULL AND next_attempt_at > NOW()'));
        $this->assertSame(['sent' => 0, 'retry' => 0, 'failed' => 0], $broken->processDue(), 'not due yet');

        for ($i = 1; $i < 5; $i++) {
            $this->pdo()->exec('UPDATE mail_queue SET next_attempt_at = NOW() - INTERVAL 1 MINUTE');
            $stats = $broken->processDue();
            $this->assertSame($i < 4 ? 'retry' : 'failed', $i < 4 ? array_search(1, $stats, true) : array_search(1, $stats, true), "round $i");
        }
        $this->assertSame(1, $this->count('failed_at IS NOT NULL AND attempts = 5'));
        $this->assertSame(['pending' => 0, 'failed' => 1], $broken->counts());

        $this->assertSame(1, $broken->retryFailed());
        $this->assertSame(['pending' => 1, 'failed' => 0], $broken->counts());
    }

    public function testQueuedMailIsSentByTheProcessor(): void
    {
        $this->pdo()->exec('DELETE FROM mail_queue');
        $good = $this->queue('log');
        $good->queue('b@example.com', 'Bulk', '<p>x</p>', 'x');
        $this->assertSame(['pending' => 1, 'failed' => 0], $good->counts());
        $this->assertSame(['sent' => 1, 'retry' => 0, 'failed' => 0], $good->processDue());
        $this->assertSame(1, $this->count('sent_at IS NOT NULL'));
        $this->assertSame(['sent' => 0, 'retry' => 0, 'failed' => 0], $good->processDue(), 'sent mail is not sent twice');
    }

    public function testBackoffGrows(): void
    {
        $this->assertSame(5, MailQueue::backoff(1));
        $this->assertSame(1440, MailQueue::backoff(5));
        $this->assertSame(1440, MailQueue::backoff(9));
    }
}
