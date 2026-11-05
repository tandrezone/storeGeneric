<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Router;
use App\I18n\Translator;
use App\Repository\NewsletterRepository;
use App\Repository\SettingRepository;
use App\View\View;
use RuntimeException;

/**
 * Newsletter with double opt-in. subscribe() emails a confirmation link; only
 * confirmed people receive campaigns, each with its own unsubscribe link.
 * Switched on in Admin → Newsletter (setting newsletter_enabled).
 */
final class NewsletterService
{
    /** Minutes between confirmation emails to the same address. */
    private const RESEND_MINUTES = 10;

    public function __construct(
        private readonly NewsletterRepository $subscribers,
        private readonly SettingRepository $settings,
        private readonly MailQueue $mail,
        private readonly View $view,
        private readonly Router $router,
        private readonly OrderLinks $links,
        private readonly StoreSettings $store,
        private readonly Translator $translator,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->get('newsletter_enabled', '0') === '1';
    }

    /**
     * @throws RuntimeException with a message safe to show the visitor (only for an invalid address:
     *                          every valid one gets the same answer, so the form can't be used to probe who is subscribed)
     */
    public function subscribe(string $email, ?string $locale): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw new RuntimeException($this->translator->trans('Please enter a valid email address.'));
        }

        $row = $this->subscribers->findByEmail($email);
        if ($row === null) {
            $this->subscribers->create($email, $locale);
            $row = $this->subscribers->findByEmail($email);
        } elseif ($row['confirmed_at'] !== null && $row['unsubscribed_at'] === null) {
            return; // already on the list; say nothing different
        } elseif ($row['unsubscribed_at'] !== null) {
            $this->subscribers->reopen((int) $row['id'], $locale);
            $row = $this->subscribers->findByEmail($email);
        }

        $sent = $row['confirm_sent_at'] ?? null;
        if ($sent !== null && strtotime((string) $sent) > time() - self::RESEND_MINUTES * 60) {
            return; // confirmation already on its way
        }
        $this->subscribers->markConfirmationSent((int) $row['id']);
        $this->translator->withLocale($locale ?: $this->store->language(), function () use ($row, $email): void {
            $vars = $this->vars() + ['confirm_url' => $this->links->baseUrl() . $this->router->url('newsletter.confirm', [], ['token' => $row['token']])];
            $this->mail->deliver(
                $email,
                $this->store->name() . ' — ' . $this->translator->trans('Please confirm your subscription'),
                $this->view->render('email/newsletter_confirm.html.twig', $vars),
                $this->view->render('email/newsletter_confirm.txt.twig', $vars),
                $this->store->email()
            );
        });
    }

    public function confirm(string $token): bool
    {
        $row = $this->find($token);
        if ($row === null) {
            return false;
        }
        $this->subscribers->confirm((int) $row['id']);

        return true;
    }

    public function unsubscribe(string $token): bool
    {
        $row = $this->find($token);
        if ($row === null) {
            return false;
        }
        $this->subscribers->unsubscribe((int) $row['id']);

        return true;
    }

    /**
     * Queues a campaign for everyone subscribed (the mail:process command sends it).
     *
     * @return int number of recipients
     */
    public function sendCampaign(string $subject, string $body, ?string $sentBy): int
    {
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new RuntimeException($this->translator->trans('A subject and a message are required.'));
        }
        $count = 0;
        foreach ($this->subscribers->active() as $subscriber) {
            $this->queueTo((string) $subscriber['email'], (string) $subscriber['token'], $subject, $body, ($subscriber['locale'] ?? null) ?: null);
            $count++;
        }
        $this->subscribers->recordCampaign(mb_substr($subject, 0, 190), $body, $count, $sentBy);

        return $count;
    }

    /** Sends the campaign to one address right now, so staff can check how it looks. */
    public function sendTest(string $to, string $subject, string $body): void
    {
        $this->queueTo($to, 'test', $subject, $body, null, true);
    }

    private function queueTo(string $email, string $token, string $subject, string $body, ?string $locale, bool $now = false): void
    {
        $this->translator->withLocale($locale ?: $this->store->language(), function () use ($email, $token, $subject, $body, $now): void {
            $vars = $this->vars() + [
                'subject'         => $subject,
                'paragraphs'      => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: []), static fn (string $p): bool => $p !== '')),
                'unsubscribe_url' => $this->links->baseUrl() . $this->router->url('newsletter.unsubscribe', [], ['token' => $token]),
            ];
            $html = $this->view->render('email/newsletter.html.twig', $vars);
            $text = $this->view->render('email/newsletter.txt.twig', $vars);
            $full = $this->store->name() . ' — ' . $subject;
            $now ? $this->mail->deliver($email, $full, $html, $text, $this->store->email()) : $this->mail->queue($email, $full, $html, $text, $this->store->email());
        });
    }

    /** @return array<string, mixed> */
    private function vars(): array
    {
        return ['store_name' => $this->store->name(), 'store_email' => $this->store->email(), 'base_url' => $this->links->baseUrl()];
    }

    /** @return array<string, mixed>|null */
    private function find(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $this->subscribers->findByToken($token) : null;
    }
}
