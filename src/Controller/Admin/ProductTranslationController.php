<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\I18n\Translator;
use App\Repository\ProductRepository;
use App\Service\ProductTranslations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * "Translate with AI" (Admin → Products → Translations): asks Gemini for a
 * translation of a product's original text and returns the fields. Nothing is
 * saved — the admin reviews them in the form, then saves.
 */
final class ProductTranslationController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ProductRepository $products,
        private readonly ProductTranslations $translations,
        private readonly Translator $translator,
    ) {
    }

    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $productId = (int) ($body['product_id'] ?? 0);
        $locale = (string) ($body['locale'] ?? '');

        if ($productId <= 0 || !$this->translations->isTranslatable($locale)) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('A product and a language are required.')], 400);
        }
        $product = $this->products->findForAdmin($productId);
        if ($product === null) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('Product not found.')], 404);
        }

        try {
            $fields = $this->translations->suggest($product, $locale);
        } catch (Throwable $e) {
            return $this->responder->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        return $this->responder->json(['success' => true, 'fields' => $fields]);
    }
}
