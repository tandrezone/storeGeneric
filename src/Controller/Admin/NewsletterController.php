<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\CsvDownload;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\NewsletterRepository;
use App\Repository\SettingRepository;
use App\Security\AdminAuthenticator;
use App\Service\NewsletterService;
use App\Service\StoreSettings;
use App\Support\Csv;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** Admin → Newsletter: switch it on, see the list, export it, write and send a campaign. */
final class NewsletterController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly NewsletterRepository $subscribers,
        private readonly NewsletterService $newsletter,
        private readonly SettingRepository $settings,
        private readonly StoreSettings $store,
        private readonly AdminAuthenticator $auth,
        private readonly CsvDownload $csv,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $handle = Csv::open(['email', 'language', 'confirmed_at', 'unsubscribed_at', 'signed_up_at']);
        foreach ($this->subscribers->active() as $row) {
            Csv::writeRow($handle, [$row['email'], $row['locale'], $row['confirmed_at'], $row['unsubscribed_at'], $row['created_at']]);
        }

        return $this->csv->response($handle, 'newsletter-subscribers-' . date('Y-m-d') . '.csv');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $subject = (string) ($body['subject'] ?? '');
        $message = (string) ($body['message'] ?? '');

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'settings':
                    $this->settings->set('newsletter_enabled', !empty($body['enabled']) ? '1' : '0');
                    $this->session->flash('success', $this->translator->trans('Newsletter settings saved.'));
                    break;
                case 'test':
                    $this->checkCampaign($subject, $message);
                    $this->newsletter->sendTest($this->store->email(), $subject, $message);
                    $this->session->flash('success', $this->translator->trans('A test email was sent to {email}.', ['email' => $this->store->email()]));
                    break;
                case 'send':
                    $count = $this->newsletter->sendCampaign($subject, $message, $this->auth->user()['username'] ?? null);
                    $this->session->flash('success', $this->translator->transPlural('The campaign is queued for {count} subscriber. Run bin/console mail:process (cron) to send it.', $count));
                    break;
            }
        } catch (RuntimeException $e) {
            return $this->page($request, [$e->getMessage()], $body, 422);
        }

        return $this->responder->redirectToRoute('admin.newsletter');
    }

    private function checkCampaign(string $subject, string $message): void
    {
        if (trim($subject) === '' || trim($message) === '') {
            throw new RuntimeException($this->translator->trans('A subject and a message are required.'));
        }
    }

    /** @param list<string> $errors @param array<string, mixed> $input */
    private function page(ServerRequestInterface $request, array $errors = [], array $input = [], int $status = 200): ResponseInterface
    {
        return $this->responder->view($request, 'admin/newsletter.html.twig', [
            'enabled'   => $this->newsletter->enabled(),
            'counts'    => $this->subscribers->counts(),
            'recent'    => $this->subscribers->recent(30),
            'campaigns' => $this->subscribers->campaigns(10),
            'errors'    => $errors,
            'input'     => $input,
        ], $status);
    }
}
