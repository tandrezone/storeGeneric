<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Router;
use App\I18n\Translator;
use App\Repository\CartRepository;
use App\Repository\SettingRepository;
use App\View\View;
use Throwable;

/**
 * One email to a signed-in customer whose cart sat untouched for a while and
 * who hasn't ordered since, with a link that puts the items back in their
 * cart. Off by default (Admin → Settings → Abandoned carts); run
 * `bin/console carts:remind` from cron. Only customers with a verified
 * account are written to — never visitors.
 */
final class CartReminderService
{
    public const DEFAULT_HOURS = 24;
    public const MAX_AGE_DAYS = 7;

    public function __construct(
        private readonly CartRepository $carts,
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
        return $this->settings->get('abandoned_cart_enabled', '0') === '1';
    }

    public function afterHours(): int
    {
        return max(1, min(168, (int) $this->settings->get('abandoned_cart_hours', (string) self::DEFAULT_HOURS)));
    }

    public function recoverUrl(int $cartId): string
    {
        return $this->links->baseUrl() . $this->router->url('cart.recover', [], ['cart' => $cartId, 'sig' => $this->links->sign('cart', (string) $cartId)]);
    }

    /** @return int reminders queued */
    public function remind(?int $afterHours = null): int
    {
        $sent = 0;
        foreach ($this->carts->abandoned($afterHours ?? $this->afterHours(), self::MAX_AGE_DAYS) as $cart) {
            try {
                $locale = ($cart['locale'] ?? null) ?: $this->store->language();
                $items = $this->carts->items((int) $cart['id'], $locale === $this->store->language() ? '' : $locale);
                if ($items === []) {
                    continue;
                }
                $this->translator->withLocale($locale, function () use ($cart, $items): void {
                    $vars = [
                        'name' => $cart['name'], 'items' => $items, 'cart_url' => $this->recoverUrl((int) $cart['id']),
                        'total' => array_sum(array_map(static fn (array $i): float => (float) $i['price'] * (int) $i['quantity'], $items)),
                        'store_name' => $this->store->name(), 'store_email' => $this->store->email(), 'base_url' => $this->links->baseUrl(),
                    ];
                    $this->mail->queue(
                        (string) $cart['email'],
                        $this->store->name() . ' — ' . $this->translator->trans('You left something in your cart'),
                        $this->view->render('email/cart_reminder.html.twig', $vars),
                        $this->view->render('email/cart_reminder.txt.twig', $vars),
                        $this->store->email()
                    );
                });
                $this->carts->markReminded((int) $cart['id']);
                $sent++;
            } catch (Throwable) {
                // try again on the next run
            }
        }

        return $sent;
    }
}
