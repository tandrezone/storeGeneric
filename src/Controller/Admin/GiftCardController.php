<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\GiftCardRepository;
use App\Security\AdminAuthenticator;
use App\Service\GiftCardNotifier;
use App\Service\GiftCardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** Admin → Gift cards: issue a card (optionally emailing it), switch cards on / off, top up or correct a balance, see the ledger. */
final class GiftCardController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly GiftCardRepository $cards,
        private readonly GiftCardService $service,
        private readonly GiftCardNotifier $notifier,
        private readonly AdminAuthenticator $auth,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $by = $this->auth->user()['username'] ?? null;
        $id = (int) ($body['id'] ?? 0);

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'create':
                    $card = $this->service->issue(
                        (float) str_replace(',', '.', (string) ($body['amount'] ?? '0')),
                        (string) ($body['code'] ?? ''),
                        (string) ($body['expires_at'] ?? ''),
                        trim((string) ($body['recipient_email'] ?? '')),
                        (string) ($body['message'] ?? ''),
                        $by
                    );
                    $sent = !empty($body['send_email']) && $this->notifier->send($card);
                    $this->session->flash('success', $this->translator->trans('Gift card {code} created.', ['code' => $card['code']]) . ($sent ? ' ' . $this->translator->trans('The recipient has been emailed.') : ''));

                    return $this->responder->redirectToRoute('admin.giftcards', [], ['card' => $card['id']]);
                case 'toggle':
                    if (($card = $this->cards->find($id)) !== null) {
                        $this->cards->setActive($id, !(int) $card['is_active']);
                        $this->session->flash('success', $this->translator->trans((int) $card['is_active'] ? '{code} deactivated.' : '{code} activated.', ['code' => $card['code']]));
                    }
                    break;
                case 'adjust':
                    $this->service->adjust($id, (float) str_replace(',', '.', (string) ($body['delta'] ?? '0')), (string) ($body['note'] ?? ''), $by);
                    $this->session->flash('success', $this->translator->trans('Balance updated.'));

                    return $this->responder->redirectToRoute('admin.giftcards', [], ['card' => $id]);
                case 'resend':
                    if (($card = $this->cards->find($id)) !== null) {
                        $this->session->flash($this->notifier->send($card) ? 'success' : 'error', $this->translator->trans($card['recipient_email'] ? 'Email sent.' : 'This card has no recipient email.'));
                    }
                    break;
            }
        } catch (RuntimeException $e) {
            return $this->page($request, [$e->getMessage()], $body, 422);
        }

        return $this->responder->redirectToRoute('admin.giftcards');
    }

    /** @param list<string> $errors @param array<string, mixed> $input */
    private function page(ServerRequestInterface $request, array $errors = [], array $input = [], int $status = 200): ResponseInterface
    {
        $query = $request->getQueryParams();
        $total = $this->cards->count();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, (int) ($query['page'] ?? 1)));
        $open = isset($query['card']) ? $this->cards->find((int) $query['card']) : null;

        return $this->responder->view($request, 'admin/giftcards.html.twig', [
            'cards'        => $this->cards->page(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'open'         => $open,
            'transactions' => $open !== null ? $this->cards->transactions((int) $open['id']) : [],
            'liability'    => $this->cards->liability(),
            'page'         => $page,
            'total_pages'  => $totalPages,
            'errors'       => $errors,
            'input'        => $input,
        ], $status);
    }
}
