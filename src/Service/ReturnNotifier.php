<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Router;
use App\I18n\Translator;
use App\Repository\ReturnRepository;
use App\Support\Config;
use App\View\View;
use Psr\Log\LoggerInterface;
use Throwable;

/** Emails for return requests: the admin is alerted of a new one, the customer of each decision. */
final class ReturnNotifier
{
    public function __construct(
        private readonly MailQueue $mail,
        private readonly Router $router,
        private readonly View $view,
        private readonly ReturnRepository $returns,
        private readonly OrderLinks $links,
        private readonly StoreSettings $store,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
    ) {
    }

    public function requested(int $returnId): void
    {
        $request = $this->returns->find($returnId);
        if ($request === null) {
            return;
        }
        $to = $this->config->get('ADMIN_NOTIFY_EMAIL', $this->store->email());
        $this->translator->withLocale($this->store->language(), fn () => $this->send($to, 'return_requested', $this->translator->trans('New return request for order {number}', ['number' => $request['order_number']]), [
            'request'   => $request,
            'reasons'   => ReturnService::REASONS,
            'admin_url' => $this->links->baseUrl() . $this->router->url('admin.returns'),
        ], (string) $request['email']));
    }

    public function updated(int $returnId): void
    {
        $request = $this->returns->find($returnId);
        if ($request === null) {
            return;
        }
        $this->translator->withLocale(($request['locale'] ?? null) ?: $this->store->language(), fn () => $this->send((string) $request['email'], 'return_update', $this->translator->trans('Your return for order {number}: {status}', [
            'number' => $request['order_number'],
            'status' => $this->translator->trans(ucfirst((string) $request['status'])),
        ]), [
            'request'   => $request,
            'order_url' => $this->links->trackUrl((string) $request['order_number']),
        ], $this->store->email()));
    }

    /** @param array<string, mixed> $vars */
    private function send(string $to, string $template, string $subject, array $vars, ?string $replyTo): void
    {
        try {
            $vars += ['store_name' => $this->store->name(), 'store_email' => $this->store->email(), 'base_url' => $this->links->baseUrl()];
            $html = $this->view->render('email/' . $template . '.html.twig', $vars);
            $text = $this->view->render('email/' . $template . '.txt.twig', $vars);
            $this->mail->deliver($to, $this->store->name() . ' — ' . $subject, $html, $text, $replyTo);
        } catch (Throwable $e) {
            $this->logger->error('Return email failed', ['template' => $template, 'to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
