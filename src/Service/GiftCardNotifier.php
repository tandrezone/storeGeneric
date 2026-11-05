<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\View\View;
use Psr\Log\LoggerInterface;
use Throwable;

/** Emails a newly issued gift card (code, amount, message) to its recipient. */
final class GiftCardNotifier
{
    public function __construct(
        private readonly MailQueue $mail,
        private readonly View $view,
        private readonly OrderLinks $links,
        private readonly StoreSettings $store,
        private readonly Translator $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $card @return bool true when the email was sent or queued */
    public function send(array $card): bool
    {
        if (empty($card['recipient_email'])) {
            return false;
        }
        try {
            return $this->translator->withLocale($this->store->language(), function () use ($card): bool {
                $vars = ['card' => $card, 'shop_url' => $this->links->baseUrl() . '/', 'store_name' => $this->store->name(), 'store_email' => $this->store->email(), 'base_url' => $this->links->baseUrl()];

                return $this->mail->deliver(
                    (string) $card['recipient_email'],
                    $this->store->name() . ' — ' . $this->translator->trans('Your gift card'),
                    $this->view->render('email/gift_card.html.twig', $vars),
                    $this->view->render('email/gift_card.txt.twig', $vars),
                    $this->store->email()
                );
            });
        } catch (Throwable $e) {
            $this->logger->error('Gift card email failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
