<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Infrastructure\Mailer;
use App\View\View;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Customer account emails (templates/email/customer_*.html.twig + .txt.twig):
 * welcome / verify-your-email and password reset. Like OrderNotifier,
 * sending never throws — a failure is logged and returns false.
 */
final class CustomerNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly View $view,
        private readonly StoreSettings $store,
        private readonly OrderLinks $links,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
    ) {
    }

    /** After registering ($welcome) or changing the email address: the link that verifies it. */
    public function verifyEmail(string $to, string $name, string $verifyUrl, bool $welcome): bool
    {
        return $this->send($to, 'customer_verify', $this->translator->trans($welcome ? 'Welcome! Please confirm your email' : 'Please confirm your email'), [
            'name'       => $name,
            'verify_url' => $verifyUrl,
            'welcome'    => $welcome,
        ]);
    }

    public function passwordReset(string $to, string $name, string $resetUrl, int $minutes): bool
    {
        return $this->send($to, 'customer_password_reset', $this->translator->trans('Reset your password'), [
            'name'      => $name,
            'reset_url' => $resetUrl,
            'minutes'   => $minutes,
        ]);
    }

    /** @param array<string, mixed> $vars */
    private function send(string $to, string $template, string $subject, array $vars): bool
    {
        try {
            $vars += ['store_name' => $this->store->name(), 'store_email' => $this->store->email(), 'base_url' => $this->links->baseUrl()];
            $html = $this->view->render('email/' . $template . '.html.twig', $vars);
            $text = $this->view->render('email/' . $template . '.txt.twig', $vars);
            $this->mailer->send($to, $this->store->name() . ' — ' . $subject, $html, $text, $this->store->email());
            $this->logger->info('Email sent', ['template' => $template, 'to' => $to, 'transport' => $this->mailer->transport()]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Email failed', ['template' => $template, 'to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
