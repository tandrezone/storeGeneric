<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\MailQueue;

/** Sends queued and retried emails. Run it every few minutes from cron. */
final class ProcessMailQueueCommand implements Command
{
    public function __construct(private readonly MailQueue $queue)
    {
    }

    public function name(): string
    {
        return 'mail:process';
    }

    public function description(): string
    {
        return 'Send queued emails and retry failed ones (run from cron); --retry-failed re-queues given-up mail';
    }

    public function usage(): string
    {
        return '[--limit=50] [--retry-failed] [--status]';
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->hasOption('status')) {
            $counts = $this->queue->counts();
            $output->line("Pending: {$counts['pending']}, given up: {$counts['failed']}");

            return 0;
        }
        if ($input->hasOption('retry-failed')) {
            $output->line('Re-queued ' . $this->queue->retryFailed() . ' message(s).');
        }
        $limit = (int) ($input->option('limit') ?? 50);
        if ($limit < 1) {
            $output->error('--limit must be at least 1.');

            return 1;
        }
        $stats = $this->queue->processDue($limit);
        $output->line("Sent {$stats['sent']}, will retry {$stats['retry']}, gave up on {$stats['failed']}.");

        return 0;
    }
}
