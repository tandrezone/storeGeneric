<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Infrastructure\EcbRates;
use App\Service\CurrencyService;

/** Refreshes the display-currency rates from the ECB when automatic updates are on in Settings (daily from cron). */
final class UpdateCurrencyRatesCommand implements Command
{
    public function __construct(private readonly CurrencyService $currencies, private readonly EcbRates $ecb)
    {
    }

    public function name(): string
    {
        return 'currency:update';
    }

    public function description(): string
    {
        return 'Refresh the "≈ price" currency rates from the ECB (needs Settings → Currencies → automatic on, or --force)';
    }

    public function usage(): string
    {
        return '[--force]';
    }

    public function run(Input $input, Output $output): int
    {
        if (!$this->currencies->autoUpdate() && !$input->hasOption('force')) {
            $output->line('Automatic currency updates are off (Admin → Settings → Currencies). Nothing done; use --force to override.');

            return 0;
        }
        $count = $this->currencies->updateFromEcb($this->ecb);
        $output->line("Updated {$count} rate(s) from the ECB.");

        return 0;
    }
}
