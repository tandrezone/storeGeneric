<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Repository\AuditLogRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Admin → Activity log (owners and managers): who did what, filterable by user, action and date. */
final class ActivityController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly Responder $responder,
        private readonly AuditLogRepository $log,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $usernames = $this->log->usernames();
        $actions = $this->log->actions();

        $user = (string) ($query['user'] ?? '');
        $action = (string) ($query['action'] ?? '');
        $filters = [
            'username' => in_array($user, $usernames, true) ? $user : null,
            'action'   => in_array($action, $actions, true) ? $action : null,
            'from'     => $this->date($query['from'] ?? ''),
            'to'       => $this->date($query['to'] ?? ''),
        ];

        $total = $this->log->countFiltered($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, (int) ($query['page'] ?? 1)));

        $entries = $this->log->page($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        foreach ($entries as &$entry) {
            $details = json_decode((string) $entry['details'], true);
            $entry['details_pretty'] = is_array($details) && $details !== []
                ? (string) json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '';
        }
        unset($entry);

        return $this->responder->view($request, 'admin/activity.html.twig', [
            'entries'     => $entries,
            'usernames'   => $usernames,
            'actions'     => $actions,
            'filters'     => $filters,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
        ]);
    }

    private function date(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
