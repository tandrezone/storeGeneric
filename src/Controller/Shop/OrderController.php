<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Http\Session;
use App\Payment\PaymentRegistry;
use App\Repository\OrderLookupAttemptRepository;
use App\Repository\OrderRepository;
use App\Service\OrderLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where customers land after paying (or placing an offline-payment order),
 * and the "track your order" page (order number + email). Both need the
 * signed ?key= from OrderLinks or the session that placed the order —
 * order numbers alone are guessable.
 */
final class OrderController
{
    private const LOOKUP_WINDOW_MINUTES = 15;
    private const LOOKUP_MAX_PER_SESSION = 5;
    private const LOOKUP_MAX_PER_IP = 20;
    private const SESSION_ATTEMPTS = 'order_lookup_attempts';

    public function __construct(
        private readonly Responder $responder,
        private readonly OrderRepository $orders,
        private readonly PaymentRegistry $payments,
        private readonly OrderLinks $links,
        private readonly OrderLookupAttemptRepository $attempts,
        private readonly Session $session,
    ) {
    }

    public function confirmation(ServerRequestInterface $request): ResponseInterface
    {
        $order = $this->authorizedOrder($request);
        $method = $order !== null ? $this->payments->get((string) $order['payment_method']) : null;

        return $this->responder->view($request, 'shop/confirmation.html.twig', [
            'order'                => $order,
            'payment_label'        => $order !== null ? $this->payments->label((string) $order['payment_method']) : '',
            'payment_instructions' => $method !== null && $method->isOffline() ? $method->instructions($order) : '',
            'track_url'            => $order !== null ? $this->links->trackUrl((string) $order['order_number']) : null,
        ]);
    }

    /** GET: the lookup form, or the order when the link carries a valid key. */
    public function track(ServerRequestInterface $request): ResponseInterface
    {
        $order = $this->authorizedOrder($request);

        return $this->responder->view($request, 'shop/order-track.html.twig', [
            'order'         => $order,
            'payment_label' => $order !== null ? $this->payments->label((string) $order['payment_method']) : '',
            'form'          => ['order' => (string) ($request->getQueryParams()['order'] ?? ''), 'email' => ''],
            'errors'        => [],
        ]);
    }

    /** POST: order number + email → redirect to the signed order view. */
    public function lookup(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $number = strtoupper(trim((string) ($body['order'] ?? '')));
        $email = trim((string) ($body['email'] ?? ''));
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $form = ['order' => $number, 'email' => $email];

        if ($this->tooManyAttempts($ip)) {
            return $this->lookupForm($request, $form, ['Too many attempts. Please wait ' . self::LOOKUP_WINDOW_MINUTES . ' minutes and try again.'], 429);
        }
        if ($number === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->lookupForm($request, $form, ['Enter your order number and the email you used at checkout.'], 422);
        }

        $order = $this->orders->findByNumberAndEmail($number, $email);
        if ($order === null) {
            $this->recordFailure($ip);

            return $this->lookupForm($request, $form, ['We couldn\'t find an order with that number and email.'], 422);
        }

        return $this->responder->redirectToRoute('order.track', [], $this->links->query((string) $order['order_number']));
    }

    /**
     * @param array{order: string, email: string} $form
     * @param list<string> $errors
     */
    private function lookupForm(ServerRequestInterface $request, array $form, array $errors, int $status): ResponseInterface
    {
        return $this->responder->view($request, 'shop/order-track.html.twig', [
            'order'         => null,
            'payment_label' => '',
            'form'          => $form,
            'errors'        => $errors,
        ], $status);
    }

    /** @return array<string, mixed>|null the order in ?order=, if the visitor may see it */
    private function authorizedOrder(ServerRequestInterface $request): ?array
    {
        $query = $request->getQueryParams();
        $number = trim((string) ($query['order'] ?? ''));
        if ($number === '' || !$this->links->canView($number, (string) ($query['key'] ?? ''))) {
            return null;
        }

        return $this->orders->findByNumber($number);
    }

    private function tooManyAttempts(string $ip): bool
    {
        $recent = array_filter(
            (array) $this->session->get(self::SESSION_ATTEMPTS, []),
            static fn (mixed $time) => is_int($time) && $time > time() - self::LOOKUP_WINDOW_MINUTES * 60
        );
        $this->session->set(self::SESSION_ATTEMPTS, array_values($recent));

        return count($recent) >= self::LOOKUP_MAX_PER_SESSION
            || $this->attempts->countRecent($ip, self::LOOKUP_WINDOW_MINUTES) >= self::LOOKUP_MAX_PER_IP;
    }

    private function recordFailure(string $ip): void
    {
        $recent = (array) $this->session->get(self::SESSION_ATTEMPTS, []);
        $recent[] = time();
        $this->session->set(self::SESSION_ATTEMPTS, $recent);
        $this->attempts->record($ip);
    }
}
