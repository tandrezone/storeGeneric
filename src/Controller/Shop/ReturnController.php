<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\OrderRepository;
use App\Service\OrderLinks;
use App\Service\ReturnService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** "Request a return" for a shipped order: GET/POST /order/return?order=…&key=… (same access rule as the order pages). */
final class ReturnController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly OrderRepository $orders,
        private readonly OrderLinks $links,
        private readonly ReturnService $returns,
        private readonly Translator $translator,
    ) {
    }

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        [$order, $query] = $this->authorized($request);

        return $this->page($request, $order, $query);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        [$order, $query] = $this->authorized($request);
        $body = (array) $request->getParsedBody();

        try {
            $this->returns->request($order, (array) ($body['qty'] ?? []), (string) ($body['reason'] ?? ''), (string) ($body['details'] ?? ''));
        } catch (RuntimeException $e) {
            return $this->page($request, $order, $query, [$e->getMessage()], $body, 422);
        }
        $this->session->flash('success', $this->translator->trans('Your return request has been sent. We will email you our decision.'));

        return $this->responder->redirectToRoute('order.track', [], $query);
    }

    /**
     * @param array{order: string, key: string} $query
     * @param list<string> $errors
     * @param array<string, mixed> $input
     */
    private function page(ServerRequestInterface $request, array $order, array $query, array $errors = [], array $input = [], int $status = 200): ResponseInterface
    {
        return $this->responder->view($request, 'shop/return.html.twig', [
            'order'      => $order,
            'returnable' => $this->returns->returnable($order),
            'reasons'    => ReturnService::REASONS,
            'window'     => $this->returns->windowDays(),
            'query'      => $query,
            'errors'     => $errors,
            'input'      => $input,
        ], $status);
    }

    /** @return array{0: array<string, mixed>, 1: array{order: string, key: string}} */
    private function authorized(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $number = strtoupper(trim((string) ($params['order'] ?? '')));
        $key = (string) ($params['key'] ?? '');
        if ($number === '' || !$this->links->canView($number, $key)) {
            throw HttpException::notFound('Order not found.');
        }
        $order = $this->orders->findByNumber($number) ?? throw HttpException::notFound('Order not found.');

        return [$order, ['order' => $number, 'key' => $this->links->key($number)]];
    }
}
