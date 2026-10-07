<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Support\Config;
use RuntimeException;

/**
 * Minimal example client for the Gemini API.
 * Docs: https://ai.google.dev/api/generate-content
 *
 * This is intentionally bare — generateText() sends a prompt and returns
 * the plain text reply. Build the actual "magic edit" prompt/response
 * parsing (e.g. asking for structured JSON edits to a product) on top
 * of this call.
 */
final class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Sends a single prompt and returns Gemini's plain text reply.
     */
    public function generateText(string $prompt): string
    {
        $apiKey = $this->config->get('GEMINI_API_KEY');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $url = self::BASE_URL . '/models/' . $this->config->get('GEMINI_MODEL', 'gemini-flash-latest') . ':generateContent';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400 || !is_array($decoded)) {
            throw new RuntimeException('Gemini returned an unexpected response (HTTP ' . $httpCode . '): ' . $response);
        }

        return (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    /**
     * Sends an image plus an editing instruction to Gemini's image model
     * and returns the edited image's raw bytes.
     *
     * Throws if the request fails or the reply doesn't contain an image —
     * callers should treat that as "leave the original image alone"
     * rather than retry with the same input.
     */
    public function editImage(string $imageData, string $mimeType, string $prompt): string
    {
        $apiKey = $this->config->get('GEMINI_API_KEY');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $url = self::BASE_URL . '/models/' . $this->config->get('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image') . ':generateContent';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageData)]],
                    ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini image request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400 || !is_array($decoded)) {
            throw new RuntimeException('Gemini returned an unexpected response (HTTP ' . $httpCode . '): ' . $response);
        }

        $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $base64 = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
            if (!is_string($base64) || $base64 === '') {
                continue;
            }

            $bytes = base64_decode($base64, true);
            if ($bytes !== false) {
                return $bytes;
            }
        }

        throw new RuntimeException('Gemini reply did not contain an edited image.');
    }

    /**
     * Best-effort web image search via Gemini's Google Search grounding —
     * no separate search-API key needed, reuses GEMINI_API_KEY. Grounding
     * returns cited web *pages*, not guaranteed direct image links, so this
     * just extracts the first URL (from the model's own reply, then from
     * its grounding citations) that looks like a direct image file. Callers
     * must still verify the URL actually resolves to a real image (e.g. via
     * ImageDownloader) before trusting it — this only returns a candidate.
     * Returns null if no image-looking URL could be extracted at all.
     */
    public function findImageUrl(string $query): ?string
    {
        $apiKey = $this->config->get('GEMINI_API_KEY');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $url = self::BASE_URL . '/models/' . $this->config->get('GEMINI_MODEL', 'gemini-flash-latest') . ':generateContent';

        $prompt = <<<PROMPT
            Search the web and find one direct URL to a real, freely viewable
            product photo for: {$query}
            Reply with ONLY that single URL, no other text and no markdown.
            The URL should end in a common image extension (.jpg, .jpeg,
            .png, or .webp).
            PROMPT;

        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'tools'    => [['google_search' => (object) []]],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini search request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400 || !is_array($decoded)) {
            throw new RuntimeException('Gemini returned an unexpected response (HTTP ' . $httpCode . '): ' . $response);
        }

        $candidates = [];

        $text = trim((string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($text !== '') {
            $candidates[] = $text;
        }

        $chunks = $decoded['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
        foreach ($chunks as $chunk) {
            $uri = $chunk['web']['uri'] ?? null;
            if (is_string($uri) && $uri !== '') {
                $candidates[] = $uri;
            }
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/https?:\/\/\S+\.(?:jpe?g|png|webp)(?:\?\S*)?/i', $candidate, $matches)) {
                return $matches[0];
            }
        }

        return null;
    }
}
