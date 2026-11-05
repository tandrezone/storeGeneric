<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ProductRepository;
use App\Repository\StockAlertRepository;
use App\Service\ProductTranslations;
use App\Service\StockAlertService;
use App\Support\Slug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** POST /product/{id}/notify (leave an email for a sold-out product) and GET /stock-alert/cancel?token=… */
final class StockAlertController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly ProductRepository $products,
        private readonly ProductTranslations $translations,
        private readonly StockAlertService $service,
        private readonly StockAlertRepository $alerts,
        private readonly Translator $translator,
    ) {
    }

    public function subscribe(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $product = $this->products->findVisibleById($id, $this->translations->storefrontLocale())
            ?? throw HttpException::notFound('Sorry, that product could not be found.');
        $body = (array) $request->getParsedBody();
        $url = $this->responder->redirectToRoute('product.show', ['id' => $id, 'slug' => Slug::from((string) $product['base_name'], 'product')])->getHeaderLine('Location');

        if (trim((string) ($body['website'] ?? '')) === '') { // honeypot: bots fill every field
            try {
                $this->service->subscribe($id, (string) ($body['email'] ?? ''), $this->translator->locale());
                $this->session->flash('success', $this->translator->trans('We will email you once it is back in stock.'));
            } catch (RuntimeException $e) {
                $this->session->flash('error', $e->getMessage());
            }
        }

        return $this->responder->redirect($url);
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $done = preg_match('/^[a-f0-9]{32}$/', $token) === 1 && $this->alerts->cancel($token);
        $this->session->flash($done ? 'success' : 'error', $this->translator->trans($done ? 'The alert has been cancelled.' : 'That alert link is no longer valid.'));

        return $this->responder->redirectToRoute('home');
    }
}
