<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/** Small cURL wrapper for JSON/form calls to payment providers and other APIs. */
final class HttpClient
{
    /**
     * @param list<string>             $headers "Name: value" lines
     * @param array<mixed>|string|null $body    array → JSON (or form-encoded when $form), string → sent as-is
     * @return array{status: int, body: array<mixed>}
     */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, bool $form = false, int $timeout = 20): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
        ];

        if ($body !== null) {
            if (is_array($body)) {
                if ($form) {
                    $body = http_build_query($body);
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                } else {
                    $body = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
                    $headers[] = 'Content-Type: application/json';
                }
            }
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        $decoded = $response === '' ? [] : json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Unexpected response from the remote service (HTTP {$status}).");
        }

        return ['status' => $status, 'body' => $decoded];
    }
}
