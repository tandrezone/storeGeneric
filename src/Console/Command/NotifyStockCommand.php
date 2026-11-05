<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\StockAlertService;

/** Queues "back in stock" emails for products that can be bought again. Run it every 15 minutes from cron (then mail:process sends them). */
final class NotifyStockCommand implements Command
{
    public function __construct(private readonly StockAlertService $alerts)
    {
    }

    public function name(): string
    {
        return 'stock:notify';
    }

    public function description(): string
    {
        return 'Email visitors waiting for a sold-out product that is back in stock (run from cron)';
    }

    public function usage(): string
    {
        return '';
    }

    public function run(Input $input, Output $output): int
    {
        $output->line('Queued ' . $this->alerts->notifyDue() . ' back-in-stock email(s).');

        return 0;
    }
}
