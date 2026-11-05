<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\CartReminderService;

/** Queues abandoned-cart reminder emails (when switched on in Admin → Settings). Run hourly from cron, then mail:process sends them. */
final class RemindCartsCommand implements Command
{
    public function __construct(private readonly CartReminderService $reminders)
    {
    }

    public function name(): string
    {
        return 'carts:remind';
    }

    public function description(): string
    {
        return 'Email signed-in customers who left items in their cart (needs Settings → Abandoned carts on, or --force)';
    }

    public function usage(): string
    {
        return '[--hours=24] [--force]';
    }

    public function run(Input $input, Output $output): int
    {
        if (!$this->reminders->enabled() && !$input->hasOption('force')) {
            $output->line('Abandoned-cart reminders are switched off (Admin → Settings). Nothing done; use --force to override.');

            return 0;
        }
        $hours = $input->option('hours');
        if ($hours !== null && (!ctype_digit($hours) || (int) $hours < 1)) {
            $output->error('--hours must be a whole number of at least 1.');

            return 1;
        }
        $output->line('Queued ' . $this->reminders->remind($hours === null ? null : (int) $hours) . ' reminder email(s).');

        return 0;
    }
}
