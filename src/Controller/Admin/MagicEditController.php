<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Infrastructure\GeminiClient;
use App\Repository\ProductRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * "Magic edit" (admin products page): sends a product plus a plain-language
 * instruction to Gemini and returns the suggested fields. Nothing is saved —
 * the admin reviews the suggestion and applies what they want.
 */
final class MagicEditController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ProductRepository $products,
        private readonly GeminiClient $gemini,
    ) {
    }

    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $productId = (int) ($body['product_id'] ?? 0);
        $instruction = trim((string) ($body['instruction'] ?? ''));

        if ($productId <= 0 || $instruction === '') {
            return $this->responder->json(['success' => false, 'error' => 'A product and an instruction are required.'], 400);
        }

        $product = $this->products->findForAdmin($productId);
        if ($product === null) {
            return $this->responder->json(['success' => false, 'error' => 'Product not found.'], 404);
        }

        $prompt = <<<PROMPT
            You are editing a product in an online store catalog.

            Current product:
            name: {$product['name']}
            short_description: {$product['short_description']}
            long_description: {$product['long_description']}

            Requested change: {$instruction}

            Reply with only a JSON object containing the fields you changed, using the keys
            name, short_description, long_description. Do not wrap it in code fences.
            PROMPT;

        try {
            return $this->responder->json(['success' => true, 'suggestion' => $this->gemini->generateText($prompt)]);
        } catch (Throwable $e) {
            return $this->responder->json(['success' => false, 'error' => $e->getMessage()], 502);
        }
    }
}
