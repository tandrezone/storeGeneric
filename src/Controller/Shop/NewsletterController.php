<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Service\NewsletterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** Newsletter: POST /newsletter/subscribe, GET /newsletter/confirm?token=…, GET /newsletter/unsubscribe?token=… */
final class NewsletterController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly NewsletterService $newsletter,
        private readonly Translator $translator,
    ) {
    }

    public function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->newsletter->enabled()) {
            throw HttpException::notFound('Not found.');
        }
        $body = (array) $request->getParsedBody();
        $back = $this->safeBack((string) ($body['back'] ?? ''));

        if (trim((string) ($body['website'] ?? '')) === '') { // honeypot
            try {
                $this->newsletter->subscribe((string) ($body['email'] ?? ''), $this->translator->locale());
                $this->session->flash('success', $this->translator->trans('Almost done — we sent you an email to confirm your subscription.'));
            } catch (RuntimeException $e) {
                $this->session->flash('error', $e->getMessage());
            }
        }

        return $this->responder->redirect($back);
    }

    public function confirm(ServerRequestInterface $request): ResponseInterface
    {
        $ok = $this->newsletter->confirm((string) ($request->getQueryParams()['token'] ?? ''));
        $this->session->flash($ok ? 'success' : 'error', $this->translator->trans($ok ? 'Thank you — you are subscribed to our newsletter.' : 'That confirmation link is not valid.'));

        return $this->responder->redirectToRoute('home');
    }

    public function unsubscribe(ServerRequestInterface $request): ResponseInterface
    {
        $ok = $this->newsletter->unsubscribe((string) ($request->getQueryParams()['token'] ?? ''));
        $this->session->flash($ok ? 'success' : 'error', $this->translator->trans($ok ? 'You have been unsubscribed. You will not get our newsletter anymore.' : 'That unsubscribe link is not valid.'));

        return $this->responder->redirectToRoute('home');
    }

    private function safeBack(string $path): string
    {
        return preg_match('#^/(?!/)[^\s\\\\]*$#', $path) === 1 ? $path : '/';
    }
}
