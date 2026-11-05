<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\Responder;
use App\Service\MarketplaceOrderImporter;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * POST /api/marketplace/orders — a connector for a marketplace / delivery app posts a normalized
 * JSON order (see README → Marketplace orders). Authenticated with the API key from Settings
 * ("Authorization: Bearer mk_…"); no session, no CSRF token.
 *
 *   201 {order_number, id}            created
 *   200 {order_number, id, duplicate} already imported (safe to retry)
 *   401 / 422 {error}                 bad key / unusable order (lists every problem, or the unknown SKU)
 */
final class MarketplaceOrderController
{
    private const MAX_BODY_BYTES = 512 * 1024;

    public function __construct(
        private readonly Responder $responder,
        private readonly MarketplaceOrderImporter $importer,
    ) {
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        $token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : '';
        if (!$this->importer->keyIsValid($token)) {
            return $this->responder->json(['error' => 'Invalid or missing API key.'], 401)->withHeader('WWW-Authenticate', 'Bearer');
        }

        $raw = (string) $request->getBody();
        $payload = strlen($raw) <= self::MAX_BODY_BYTES ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            return $this->responder->json(['error' => 'The body must be a JSON object (max 512 KB).'], 422);
        }

        try {
            $result = $this->importer->import($payload);
        } catch (InvalidArgumentException $e) {
            return $this->responder->json(['error' => $e->getMessage()], 422);
        } catch (Throwable) {
            return $this->responder->json(['error' => 'The order could not be saved. Try again.'], 500);
        }

        return $this->responder->json($result, $result['duplicate'] ? 200 : 201);
    }
}
