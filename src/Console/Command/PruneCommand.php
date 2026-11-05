<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Infrastructure\Database;
use Psr\Log\LoggerInterface;

/**
 * Housekeeping: deletes old rows that only ever grow. Run it from cron, e.g.
 * daily: bin/console maintenance:prune. --dry-run only counts.
 *
 * Orders, payments and customers are never touched.
 */
final class PruneCommand implements Command
{
    /** option => default number of days */
    private const DEFAULTS = [
        'page-views-days' => 180,
        'audit-days'      => 365,
        'carts-days'      => 60,
        'attempts-days'   => 7,
        'mail-days'       => 30,
    ];

    private const BATCH = 5000;

    public function __construct(private readonly Database $db, private readonly LoggerInterface $logger)
    {
    }

    public function name(): string
    {
        return 'maintenance:prune';
    }

    public function description(): string
    {
        return 'Delete old page views, audit log, abandoned carts, expired tokens and attempt rows';
    }

    public function usage(): string
    {
        return '[--dry-run] [--page-views-days=180] [--audit-days=365] [--carts-days=60] [--attempts-days=7] [--mail-days=30]';
    }

    public function run(Input $input, Output $output): int
    {
        $days = [];
        foreach (self::DEFAULTS as $option => $default) {
            $raw = $input->option($option);
            if ($raw !== null && !ctype_digit($raw)) {
                $output->error("--{$option} must be a whole number of days.");

                return 1;
            }
            $days[$option] = $raw === null ? $default : (int) $raw;
            if ($days[$option] < 1) {
                $output->error("--{$option} must be at least 1.");

                return 1;
            }
        }
        $dry = $input->hasOption('dry-run');

        // label => [table, WHERE clause]. The days are validated integers, so inlining them is safe.
        $old = static fn (string $column, string $option): string => "{$column} < DATE_SUB(NOW(), INTERVAL {$days[$option]} DAY)";
        $targets = [
            'page views'             => ['page_views', $old('created_at', 'page-views-days')],
            'audit log entries'      => ['admin_audit_log', $old('created_at', 'audit-days')],
            'cart items'             => ['cart_items', 'cart_id IN (SELECT id FROM carts WHERE ' . $old('updated_at', 'carts-days') . ')'],
            'carts'                  => ['carts', $old('updated_at', 'carts-days')],
            'expired tokens'         => ['customer_tokens', 'expires_at < NOW()'],
            'login attempts'         => ['login_attempts', $old('created_at', 'attempts-days')],
            'order lookup attempts'  => ['order_lookup_attempts', $old('created_at', 'attempts-days')],
            'sent emails'            => ['mail_queue', 'sent_at IS NOT NULL AND ' . $old('sent_at', 'mail-days')],
            'customer auth attempts' => ['customer_auth_attempts', $old('created_at', 'attempts-days')],
        ];

        $pdo = $this->db->pdo();
        $total = 0;
        foreach ($targets as $label => [$table, $where]) {
            if ($dry) {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
            } else {
                $count = 0;
                do { // in batches, so a big table doesn't hold long locks
                    $deleted = (int) $pdo->exec("DELETE FROM {$table} WHERE {$where} LIMIT " . self::BATCH);
                    $count += $deleted;
                } while ($deleted === self::BATCH);
            }
            $total += $count;
            $output->line(sprintf('%s %d %s.', $dry ? 'Would delete' : 'Deleted', $count, $label));
        }

        $output->line(sprintf('%s %d row(s) in total%s.', $dry ? 'Would delete' : 'Deleted', $total, $dry ? ' (dry run, nothing changed)' : ''));
        if (!$dry) {
            $this->logger->info('maintenance:prune finished', ['rows' => $total] + $days);
        }

        return 0;
    }
}
