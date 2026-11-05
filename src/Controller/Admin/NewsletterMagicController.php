<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Infrastructure\GeminiClient;
use App\Service\AiStoreContext;
use App\Service\PromptLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * "Magic edit" for Admin → Newsletter: writes a campaign's subject and
 * plain-text message with Gemini from the store's products (newest and on
 * sale first, with links) plus an optional extra instruction. Nothing is
 * sent — the form is filled for review, a test and the real send.
 */
final class NewsletterMagicController
{
    private const CATALOG_LIMIT = 40;
    private const MAX_SUBJECT = 190;

    public function __construct(
        private readonly Responder $responder,
        private readonly AiStoreContext $context,
        private readonly GeminiClient $gemini,
        private readonly Translator $translator,
        private readonly PromptLibrary $prompts,
        private readonly Session $session,
    ) {
    }

    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        $instruction = mb_substr(trim((string) ($body['instruction'] ?? '')), 0, 2000);

        $vars = [
            'language'    => $this->context->languageName(),
            'store'       => $this->context->facts(),
            'catalog'     => $this->context->catalog(self::CATALOG_LIMIT, true),
            'instruction' => $instruction !== '' ? $instruction : '(none)',
            'subject'     => $subject !== '' ? $subject : '(empty)',
            'message'     => $message !== '' ? mb_substr($message, 0, 6000) : '(empty)',
        ];

        $prompt = $this->prompts->override(PromptLibrary::MAGIC_NEWSLETTER, $vars) ?? <<<PROMPT
            You write email newsletters for an online store. Write one campaign for its subscribers.

            Write in {$vars['language']}. Use only the store facts and products below — do not invent
            products, prices, discounts or discount codes. Unless the extra instruction says otherwise,
            feature a few new arrivals and anything on sale, each with its link, and end with a short
            call to visit the store.

            Store facts:
            {$vars['store']}

            Products (newest first):
            {$vars['catalog']}

            Current subject: {$vars['subject']}
            Current message: {$vars['message']}

            Extra instruction from the store owner: {$vars['instruction']}

            The message is PLAIN TEXT: no HTML, no Markdown (no **, #, or [text](url)); write links as
            full URLs on their own line; leave an empty line between paragraphs. Do not add an
            unsubscribe line — the system adds one.

            Reply with only a JSON object with the keys "subject" (at most 150 characters) and
            "message". No code fences.
            PROMPT;

        // Up to 30 seconds: release the session lock so the rest of the admin stays usable.
        $this->session->release();

        try {
            $reply = $this->gemini->generateText($prompt);
        } catch (Throwable $e) {
            return $this->responder->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        $fields = $this->parse($reply);
        if ($fields === []) {
            return $this->responder->json(['success' => false, 'error' => $this->translator->trans('The AI reply could not be read. Try again.')], 502);
        }

        return $this->responder->json(['success' => true, 'fields' => $fields]);
    }

    /** @return array<string, string> subject / message from the model's JSON reply; empty when unreadable */
    private function parse(string $reply): array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        $decoded = $start !== false && $end !== false && $end > $start ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        if (is_string($decoded['subject'] ?? null) && trim($decoded['subject']) !== '') {
            $fields['subject'] = mb_substr(trim(strip_tags($decoded['subject'])), 0, self::MAX_SUBJECT);
        }
        if (is_string($decoded['message'] ?? null) && trim($decoded['message']) !== '') {
            // Plain text only: drop any tags and normalise line endings / runs of blank lines.
            $text = str_replace(["\r\n", "\r"], "\n", strip_tags($decoded['message']));
            $fields['message'] = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
        }

        return $fields;
    }
}
