<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Router;
use App\Infrastructure\Mailer;
use App\Payment\PaymentRegistry;
use App\Repository\OrderRepository;
use App\Support\Config;
use App\View\View;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Order emails (templates/email/<name>.html.twig + .txt.twig). Sending
 * never throws: a failure is logged and checkout / webhooks carry on.
 */
final class OrderNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Router $router,
        private readonly View $view,
        private readonly OrderRepository $orders,
        private readonly PaymentRegistry $payments,
        private readonly OrderLinks $links,
        private readonly StoreSettings $store,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Order placed: offline methods get the confirmation with payment instructions now; the admin is alerted. */
    public function orderPlaced(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        $method = $order !== null ? $this->payments->get((string) $order['payment_method']) : null;
        if ($order === null || $method === null || !$method->isOffline()) {
            return; // online orders are confirmed (and the admin alerted) once paid
        }

        $instructions = $method->instructions($order);
        $this->toCustomer($order, 'order_confirmation', 'Order ' . $order['order_number'] . ' received', [
            'instructions_html' => $instructions,
            'instructions_text' => $this->plainText($instructions),
        ]);
        $this->toAdmin($order);
    }

    /**
     * Payment confirmed (webhook, PayPal return, admin "Mark as paid", or a
     * zero-total order recorded as paid at checkout). Offline orders already
     * had their confirmation when placed — except zero-total ones, which skip
     * orderPlaced() and are confirmed (and the admin alerted) here instead.
     */
    public function orderPaid(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return;
        }

        if ((float) $order['total'] > 0.0 && $this->payments->get((string) $order['payment_method'])?->isOffline()) {
            $this->toCustomer($order, 'payment_received', 'Payment received for order ' . $order['order_number']);

            return;
        }

        $this->toCustomer($order, 'order_confirmation', 'Order ' . $order['order_number'] . ' confirmed', ['instructions_html' => '', 'instructions_text' => '']);
        $this->toAdmin($order);
    }

    public function orderShipped(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order !== null) {
            $this->toCustomer($order, 'order_shipped', 'Order ' . $order['order_number'] . ' has shipped');
        }
    }

    public function orderCancelled(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order !== null) {
            $this->toCustomer($order, 'order_cancelled', 'Order ' . $order['order_number'] . ' cancelled', ['refunded' => false]);
        }
    }

    public function orderRefunded(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order !== null) {
            $this->toCustomer($order, 'order_cancelled', 'Order ' . $order['order_number'] . ' refunded', ['refunded' => true]);
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $vars
     */
    private function toCustomer(array $order, string $template, string $subject, array $vars = []): void
    {
        $this->send((string) $order['email'], $template, $subject, $vars + [
            'order'         => $order,
            'payment_label' => $this->payments->label((string) $order['payment_method']),
            'order_url'     => $this->links->trackUrl((string) $order['order_number']),
        ], $this->store->email());
    }

    /** @param array<string, mixed> $order */
    private function toAdmin(array $order): void
    {
        $to = $this->config->get('ADMIN_NOTIFY_EMAIL', $this->store->email());
        $this->send($to, 'admin_new_order', 'New order ' . $order['order_number'], [
            'order'         => $order,
            'payment_label' => $this->payments->label((string) $order['payment_method']),
            'admin_url'     => $this->links->baseUrl() . $this->router->url('admin.order', ['id' => (int) $order['id']]),
        ], (string) $order['email']);
    }

    /** Payment instructions (HTML from PaymentMethod::instructions()) as plain text. */
    private function plainText(string $html): string
    {
        $text = strip_tags((string) preg_replace(['#<br\s*/?>#i', '#</(h\d|p|div)>#i'], ["\n", "\n\n"], $html));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @param array<string, mixed> $vars */
    private function send(string $to, string $template, string $subject, array $vars, ?string $replyTo): void
    {
        try {
            $vars += ['store_name' => $this->store->name(), 'store_email' => $this->store->email(), 'base_url' => $this->links->baseUrl()];
            $html = $this->view->render('email/' . $template . '.html.twig', $vars);
            $text = $this->view->render('email/' . $template . '.txt.twig', $vars);
            $subject = $this->store->name() . ' — ' . $subject;
            $this->mailer->send($to, $subject, $html, $text, $replyTo);
            $this->logger->info('Email sent', ['template' => $template, 'to' => $to, 'transport' => $this->mailer->transport()]);
        } catch (Throwable $e) {
            $this->logger->error('Email failed', ['template' => $template, 'to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
