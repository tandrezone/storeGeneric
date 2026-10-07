<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\OrderService;

/**
 * Cancels online-payment orders that were never paid and gives their stock
 * back. Run it from cron, e.g. hourly: bin/console orders:expire --hours=48
 */
final class ExpireOrdersCommand implements Command
{
    private const DEFAULT_HOURS = 48;

    public function __construct(private readonly OrderService $orders)
    {
    }

    public function name(): string
    {
        return 'orders:expire';
    }

    public function description(): string
    {
        return 'Cancel unpaid online orders older than --hours (default 48) and restock them';
    }

    public function usage(): string
    {
        return '[--hours=48]';
    }

    public function run(Input $input, Output $output): int
    {
        $hours = (int) ($input->option('hours') ?? self::DEFAULT_HOURS);
        if ($hours < 1) {
            $output->error('--hours must be at least 1.');

            return 1;
        }

        $count = $this->orders->expireStale($hours);
        $output->line("Expired {$count} unpaid order(s) older than {$hours} hour(s).");

        return 0;
    }
}
