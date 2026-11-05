<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ProductRepository;
use App\Security\CustomerAuthenticator;
use App\Service\ReviewService;
use App\Service\ProductTranslations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** POST /product/{id}/review — a signed-in customer reviews a visible product. */
final class ReviewController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CustomerAuthenticator $auth,
        private readonly ProductRepository $products,
        private readonly ProductTranslations $translations,
        private readonly ReviewService $reviews,
        private readonly Translator $translator,
    ) {
    }

    public function submit(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $product = $this->products->findVisibleById($id, $this->translations->storefrontLocale())
            ?? throw HttpException::notFound('Sorry, that product could not be found.');
        $url = $this->responder->redirectToRoute('product.show', ['id' => $id, 'slug' => \App\Support\Slug::from((string) $product['base_name'], 'product')])->getHeaderLine('Location');

        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->responder->redirectToRoute('account.login', [], ['next' => $url]);
        }
        $body = (array) $request->getParsedBody();

        try {
            $result = $this->reviews->submit($customer, $id, $body['rating'] ?? null, (string) ($body['title'] ?? ''), (string) ($body['body'] ?? ''));
            $this->session->flash('success', $this->translator->trans($result['status'] === 'approved' ? 'Thank you! Your review is now online.' : 'Thank you! Your review will appear once we have checked it.'));
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->responder->redirect($url . '#reviews');
    }
}
