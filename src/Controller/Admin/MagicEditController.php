<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Infrastructure\GeminiClient;
use App\Controller\Admin\ProductController;
use App\Repository\ProductRepository;
use App\Security\HtmlSanitizer;
use App\Service\PromptLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDOException;
use Throwable;

/**
 * "Magic edit" (admin products page): sends a product plus a plain-language
 * instruction to Gemini and returns the suggested fields. suggest() saves
 * nothing — the admin reviews the suggestion and applies what they want.
 *
 * "Magic edit all products" runs suggest() for every id from productIds()
 * (in the browser, a few at a time), shows the changes for review, then
 * saves each kept product with apply().
 */
final class MagicEditController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ProductRepository $products,
        private readonly GeminiClient $gemini,
        private readonly HtmlSanitizer $sanitizer,
        private readonly Translator $translator,
        private readonly PromptLibrary $prompts,
        private readonly Session $session,
    ) {
    }

    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $productId = (int) ($body['product_id'] ?? 0);
        $instruction = mb_substr(trim((string) ($body['instruction'] ?? '')), 0, 2000);

        if ($productId <= 0 || $instruction === '') {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('A product and an instruction are required.')], 400);
        }

        $product = $this->products->findForAdmin($productId);
        if ($product === null) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('Product not found.')], 404);
        }

        $prompt = $this->prompts->override(PromptLibrary::MAGIC_EDIT, [
            'instruction' => $instruction,
            'name' => (string) $product['name'],
            'short_description' => (string) $product['short_description'],
            'long_description' => (string) $product['long_description'],
            'field_json' => (string) json_encode([
                'name' => (string) $product['name'],
                'short_description' => (string) $product['short_description'],
                'long_description' => (string) $product['long_description'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]) ?? <<<PROMPT
            You are editing a product in an online store catalog.

            Current product:
            name: {$product['name']}
            short_description: {$product['short_description']}
            long_description: {$product['long_description']}

            Requested change: {$instruction}

            Reply with only a JSON object containing the fields you changed, using the keys
            name, short_description, long_description. name is plain text of at most {$this->maxName()}
            characters, short_description plain text of at most {$this->maxShort()} characters,
            long_description is HTML (use p, ul, ol, li, strong, em, a only). Do not wrap it in
            code fences.
            PROMPT;

        // The AI call can take up to 30 seconds: don't hold the session lock meanwhile,
        // or every other admin request from this browser waits behind it.
        $this->session->release();

        try {
            $suggestion = $this->gemini->generateText($prompt);
        } catch (Throwable $e) {
            return $this->responder->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        $fields = $this->parseFields($suggestion);
        if ($fields === null) {
            // Empty (blocked or cut-off) or unreadable reply: a failure, not "no changes".
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('The AI reply could not be read. Try again.')], 502);
        }

        return $this->responder->json([
            'success'    => true,
            'suggestion' => $suggestion,
            'fields'     => $fields,
            // Fields whose suggestion is longer than the product form allows (saving them would fail).
            'too_long'   => array_keys(array_filter($fields, fn (string $v, string $k) => $this->validate([$k => $v]) !== null, ARRAY_FILTER_USE_BOTH)),
            // Sent back by "Save selected": refuses to overwrite edits made after this suggestion.
            'version'    => self::version($product),
            'current'    => [
                'name'              => (string) $product['name'],
                'short_description' => (string) $product['short_description'],
                // Re-sanitized: the browser shows it as HTML.
                'long_description'  => $this->sanitizer->clean((string) $product['long_description']),
            ],
        ]);
    }

    /** Every product id, so the browser can run the magic edit over the whole catalog. */
    public function productIds(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['success' => true, 'ids' => $this->products->allIds()]);
    }

    /**
     * Saves reviewed magic-edit fields (any of name, short_description,
     * long_description) on one product, with the same limits as the edit form.
     */
    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $productId = (int) ($body['product_id'] ?? 0);
        $product = $productId > 0 ? $this->products->findForAdmin($productId) : null;
        if ($product === null) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('Product not found.')], 404);
        }
        $version = (string) ($body['version'] ?? '');
        if ($version !== '' && !hash_equals(self::version($product), $version)) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('This product was changed after the suggestion was made; not saved. Run the magic edit on it again.')], 409);
        }

        $fields = [];
        foreach (['name', 'short_description'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                $fields[$key] = trim($body[$key]);
            }
        }
        if (isset($body['long_description']) && is_string($body['long_description'])) {
            $fields['long_description'] = $this->sanitizer->clean($body['long_description']);
        }

        $error = $this->validate($fields);
        if ($error !== null) {
            return $this->responder->json(['success' => false, 'error' => $error], 422);
        }

        try {
            $this->products->update($productId, $fields);
        } catch (PDOException) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('Could not save the product because of a database error. Please try again.')], 500);
        }

        return $this->responder->json(['success' => true]);
    }

    /** @param array<string, string> $fields */
    private function validate(array $fields): ?string
    {
        if ($fields === []) {
            return $this->translator->trans('Nothing to save.');
        }
        if (isset($fields['name'])) {
            if ($fields['name'] === '') {
                return $this->translator->trans('Name is required.');
            }
            if (mb_strlen($fields['name']) > ProductController::MAX_NAME) {
                return $this->translator->trans('Name can be at most {max} characters.', ['max' => ProductController::MAX_NAME]);
            }
        }
        if (isset($fields['short_description'])) {
            if ($fields['short_description'] === '') {
                return $this->translator->trans('Short description is required.');
            }
            if (mb_strlen($fields['short_description']) > ProductController::MAX_SHORT_DESCRIPTION) {
                return $this->translator->trans('Short description can be at most {max} characters.', ['max' => ProductController::MAX_SHORT_DESCRIPTION]);
            }
        }
        if (isset($fields['long_description']) && strlen($fields['long_description']) > ProductController::MAX_LONG_DESCRIPTION_BYTES) {
            return $this->translator->trans('Long description is too long.');
        }

        return null;
    }

    /** Fingerprint of the editable fields, to detect changes between suggest() and apply(). @param array<string, mixed> $product */
    private static function version(array $product): string
    {
        return hash('sha256', json_encode([(string) $product['name'], (string) $product['short_description'], (string) $product['long_description']]) ?: '');
    }

    private function maxName(): int
    {
        return ProductController::MAX_NAME;
    }

    private function maxShort(): int
    {
        return ProductController::MAX_SHORT_DESCRIPTION;
    }

    /**
     * Pulls the editable fields out of the model's reply (tolerating code
     * fences or stray prose around the JSON). [] when the model changed
     * nothing; null when the reply is empty or isn't readable JSON.
     *
     * @return array<string, string>|null
     */
    private function parseFields(string $reply): ?array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            return null;
        }

        $fields = [];
        foreach (['name', 'short_description', 'long_description'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                $fields[$key] = trim($decoded[$key]);
            }
        }
        if (isset($fields['long_description'])) {
            $fields['long_description'] = $this->sanitizer->clean($fields['long_description']);
        }

        return $fields;
    }
}
