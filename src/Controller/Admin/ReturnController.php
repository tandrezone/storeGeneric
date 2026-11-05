<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ReturnRepository;
use App\Service\ReturnService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** Admin → Returns: customer return requests; approve / reject / mark received / mark refunded, with a message to the customer. */
final class ReturnController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly ReturnRepository $returns,
        private readonly ReturnService $service,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = in_array($query['status'] ?? '', ReturnRepository::STATUSES, true) ? (string) $query['status'] : null;
        $total = $this->returns->countForAdmin($status);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, (int) ($query['page'] ?? 1)));

        $requests = $this->returns->pageForAdmin($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        foreach ($requests as &$r) {
            $r['next'] = $this->service->nextStatuses((string) $r['status']);
        }
        unset($r);

        return $this->responder->view($request, 'admin/returns.html.twig', [
            'requests'      => $requests,
            'statuses'      => ReturnRepository::STATUSES,
            'status_filter' => $status,
            'reasons'       => ReturnService::REASONS,
            'total'         => $total,
            'page'          => $page,
            'total_pages'   => $totalPages,
            'window_days'   => $this->service->windowDays(),
        ]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $this->service->setStatus((int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''), (string) ($body['response'] ?? ''));
            $this->session->flash('success', $this->translator->trans('Return request updated.'));
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->responder->redirectToRoute('admin.returns', [], array_filter(['status' => $body['filter'] ?? null]));
    }
}
