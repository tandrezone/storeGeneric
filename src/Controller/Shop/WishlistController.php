<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ProductRepository;
use App\Repository\WishlistRepository;
use App\Security\CustomerAuthenticator;
use App\Service\ProductImages;
use App\Service\ProductTranslations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Wishlist of the signed-in customer: GET /account/wishlist, POST /account/wishlist (add / remove). */
final class WishlistController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CustomerAuthenticator $auth,
        private readonly WishlistRepository $wishlist,
        private readonly ProductRepository $products,
        private readonly ProductImages $images,
        private readonly ProductTranslations $translations,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->responder->redirectToRoute('account.login', [], ['next' => '/account/wishlist']);
        }
        $ids = $this->wishlist->productIds((int) $customer['id']);

        return $this->responder->view($request, 'shop/account/wishlist.html.twig', [
            'customer' => $customer,
            'products' => $this->images->forListing($this->products->findVisibleByIds($ids, $this->translations->storefrontLocale())),
        ]);
    }

    public function toggle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $productId = (int) ($body['product_id'] ?? 0);
        $back = $this->safeBack((string) ($body['back'] ?? ''));
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->responder->redirectToRoute('account.login', [], ['next' => $back]);
        }
        $id = (int) $customer['id'];

        if ($this->products->findVisibleById($productId, '') !== null) {
            if (($body['action'] ?? '') === 'remove' || ($this->wishlist->has($id, $productId) && ($body['action'] ?? '') !== 'add')) {
                $this->wishlist->remove($id, $productId);
                $this->session->flash('success', $this->translator->trans('Removed from your wishlist.'));
            } else {
                $this->wishlist->add($id, $productId);
                $this->session->flash('success', $this->translator->trans('Saved to your wishlist.'));
            }
        }

        return $this->responder->redirect($back);
    }

    /** Only same-site paths ("/product/3-x"), never a full URL. */
    private function safeBack(string $path): string
    {
        return preg_match('#^/(?!/)[^\s\\\\]*$#', $path) === 1 ? $path : '/account/wishlist';
    }
}
