<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Router;
use App\I18n\Translator;
use App\Repository\ProductRepository;
use App\Repository\StockAlertRepository;
use App\Support\Slug;
use App\View\View;
use RuntimeException;
use Throwable;

/**
 * Back-in-stock alerts: visitors leave their email on a sold-out product; the
 * `stock:notify` command (cron) emails everyone whose product can be bought
 * again — once. Emails go through the queue.
 */
final class StockAlertService
{
    public function __construct(
        private readonly StockAlertRepository $alerts,
        private readonly ProductRepository $products,
        private readonly MailQueue $mail,
        private readonly View $view,
        private readonly Router $router,
        private readonly OrderLinks $links,
        private readonly StoreSettings $store,
        private readonly Translator $translator,
    ) {
    }

    /** @throws RuntimeException with a message safe to show the visitor */
    public function subscribe(int $productId, string $email, ?string $locale): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw new RuntimeException($this->translator->trans('Please enter a valid email address.'));
        }
        $this->alerts->subscribe($productId, $email, $locale);
    }

    /** Emails every due alert; returns how many were queued. */
    public function notifyDue(): int
    {
        $sent = 0;
        foreach ($this->alerts->due() as $alert) {
            try {
                $locale = ($alert['locale'] ?? null) ?: $this->store->language();
                $this->translator->withLocale($locale, function () use ($alert): void {
                    $url = $this->links->baseUrl() . $this->router->url('product.show', ['id' => (int) $alert['product_id'], 'slug' => Slug::from((string) $alert['product_name'], 'product')]);
                    $vars = [
                        'product_name' => $alert['product_name'], 'product_url' => $url,
                        'cancel_url'   => $this->links->baseUrl() . $this->router->url('stock-alert.cancel', [], ['token' => $alert['token']]),
                        'store_name'   => $this->store->name(), 'store_email' => $this->store->email(), 'base_url' => $this->links->baseUrl(),
                    ];
                    $this->mail->queue(
                        (string) $alert['email'],
                        $this->store->name() . ' — ' . $this->translator->trans('{product} is back in stock', ['product' => $alert['product_name']]),
                        $this->view->render('email/stock_back.html.twig', $vars),
                        $this->view->render('email/stock_back.txt.twig', $vars),
                        $this->store->email()
                    );
                });
                $this->alerts->markNotified((int) $alert['id']);
                $sent++;
            } catch (Throwable) {
                // leave it pending; the next run tries again
            }
        }

        return $sent;
    }
}
