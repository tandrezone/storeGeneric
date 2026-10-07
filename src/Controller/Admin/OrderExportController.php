<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\CsvDownload;
use App\Repository\OrderRepository;
use App\Service\CsvExport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin → Orders → Export CSV, with the list's filters (?status, q, from, to).
 * ?items=1 exports one row per order line instead of one per order.
 */
final class OrderExportController
{
    public function __construct(
        private readonly CsvDownload $download,
        private readonly CsvExport $export,
    ) {
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = (string) ($query['status'] ?? '');
        $filters = [
            'status' => in_array($status, OrderRepository::STATUSES, true) ? $status : null,
            'q'      => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100),
            'from'   => $this->date($query['from'] ?? ''),
            'to'     => $this->date($query['to'] ?? ''),
        ];

        if (!empty($query['items'])) {
            return $this->download->response($this->export->orderItems($filters), 'order-items-' . date('Ymd-His') . '.csv');
        }

        return $this->download->response($this->export->orders($filters), 'orders-' . date('Ymd-His') . '.csv');
    }

    /** A Y-m-d date from the query string, or '' (same rule as the order list). */
    private function date(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
