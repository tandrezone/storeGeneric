<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ReviewRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Admin → Reviews: approve, reject or delete customer reviews (pending ones first). */
final class ReviewController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly ReviewRepository $reviews,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = in_array($query['status'] ?? '', ReviewRepository::STATUSES, true) ? (string) $query['status'] : null;
        $total = $this->reviews->countForAdmin($status);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, (int) ($query['page'] ?? 1)));

        return $this->responder->view($request, 'admin/reviews.html.twig', [
            'reviews'       => $this->reviews->pageForAdmin($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'statuses'      => ReviewRepository::STATUSES,
            'status_filter' => $status,
            'pending'       => $this->reviews->countByStatus('pending'),
            'total_pages'   => $totalPages,
            'page'          => $page,
        ]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $id = (int) ($body['id'] ?? 0);
        $review = $this->reviews->find($id);
        $action = (string) ($body['action'] ?? '');

        if ($review !== null && in_array($action, ['approve', 'reject'], true)) {
            $this->reviews->setStatus($id, $action === 'approve' ? 'approved' : 'rejected');
            $this->session->flash('success', $this->translator->trans($action === 'approve' ? 'Review approved.' : 'Review rejected.'));
        } elseif ($review !== null && $action === 'delete') {
            $this->reviews->delete($id);
            $this->session->flash('success', $this->translator->trans('Review deleted.'));
        }

        return $this->responder->redirectToRoute('admin.reviews', [], array_filter(['status' => $body['filter'] ?? null]));
    }
}
