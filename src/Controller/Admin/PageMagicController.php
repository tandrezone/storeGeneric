<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\I18n\Translator;
use App\Infrastructure\GeminiClient;
use App\Security\HtmlSanitizer;
use App\Service\AiStoreContext;
use App\Service\PromptLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * "Magic edit" for Admin → Pages: writes a page's title and content with
 * Gemini, from what the store sells (products, categories, shipping,
 * payment methods) plus an optional extra instruction. Nothing is saved —
 * the admin's form is filled for review and saved with "Save page".
 */
final class PageMagicController
{
    /** Products described to the model (keeps the prompt a sensible size). */
    private const CATALOG_LIMIT = 80;

    /** What each built-in page is for. */
    private const PURPOSES = [
        'about'   => 'an "About us" page: who the store is, what it sells and why customers should buy here',
        'info'    => 'a shopping information page: how ordering works, shipping options and costs, payment methods, delivery',
        'support' => 'a customer support page: how to get help, contact details, and a short FAQ about orders, shipping and returns',
        'terms'   => 'terms and conditions of sale: ordering, prices, payment, shipping, returns and refunds, liability',
        'privacy' => 'a privacy policy: what personal data the store collects at checkout and in accounts, why, how long it is kept, and customers\' rights',
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly AiStoreContext $context,
        private readonly GeminiClient $gemini,
        private readonly HtmlSanitizer $sanitizer,
        private readonly Translator $translator,
        private readonly PromptLibrary $prompts,
    ) {
    }

    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $slug = mb_strtolower(trim((string) ($body['slug'] ?? '')));
        $title = trim((string) ($body['title'] ?? ''));
        $currentBody = trim(strip_tags((string) ($body['body'] ?? ''), '<h2><h3><p><ul><ol><li><strong><em><a>'));
        $instruction = mb_substr(trim((string) ($body['instruction'] ?? '')), 0, 2000);

        if ($slug === '' && $title === '' && $instruction === '') {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('Give the page an address or a title first, or describe it in the extra instruction.')], 400);
        }

        $vars = [
            'page'        => self::PURPOSES[$slug] ?? 'a page called "' . ($title !== '' ? $title : $slug) . '" (address /p/' . $slug . ')',
            'slug'        => $slug,
            'language'    => $this->context->languageName((string) ($body['locale'] ?? '')),
            'store'       => $this->context->facts(),
            'catalog'     => $this->context->catalog(self::CATALOG_LIMIT),
            'instruction' => $instruction !== '' ? $instruction : '(none)',
            'title'       => $title,
            'body'        => $currentBody !== '' ? mb_substr($currentBody, 0, 6000) : '(empty)',
        ];

        $prompt = $this->prompts->override(PromptLibrary::MAGIC_PAGE, $vars) ?? <<<PROMPT
            You write content pages for an online store. Write {$vars['page']}.

            Write in {$vars['language']}. Base everything on the store facts and catalog below; do not
            invent products, prices, company names, registration numbers, street addresses or phone
            numbers. Where a legal page needs a detail you don't have, write a clear placeholder in
            square brackets, e.g. [company address].

            Store facts:
            {$vars['store']}

            What the store sells:
            {$vars['catalog']}

            Current title: {$vars['title']}
            Current content: {$vars['body']}

            Extra instruction from the store owner: {$vars['instruction']}

            Reply with only a JSON object with the keys "title" (plain text, at most 180 characters)
            and "body" (HTML using only h2, h3, p, ul, ol, li, strong, em and a). No code fences.
            PROMPT;

        try {
            $reply = $this->gemini->generateText($prompt);
        } catch (Throwable $e) {
            return $this->responder->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        $fields = $this->parse($reply);
        if ($fields === []) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('The AI reply could not be read. Try again.'), 'reply' => $reply], 502);
        }

        return $this->responder->json(['success' => true, 'fields' => $fields]);
    }

    /** @return array<string, string> title / body from the model's JSON reply; empty when unreadable */
    private function parse(string $reply): array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        $decoded = $start !== false && $end !== false && $end > $start ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        if (is_string($decoded['title'] ?? null) && trim($decoded['title']) !== '') {
            $fields['title'] = mb_substr(trim(strip_tags($decoded['title'])), 0, 180);
        }
        if (is_string($decoded['body'] ?? null) && ($html = $this->sanitizer->cleanPage($decoded['body'])) !== '') {
            $fields['body'] = $html;
        }

        return $fields;
    }
}
