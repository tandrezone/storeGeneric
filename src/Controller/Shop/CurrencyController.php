<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Service\CurrencyService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /currency?code=USD&back=/path — pick (or, with an empty code, clear) the currency for approximate prices. */
final class CurrencyController
{
    public function __construct(private readonly Responder $responder, private readonly CurrencyService $currencies)
    {
    }

    public function select(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $this->currencies->choose(isset($query['code']) && $query['code'] !== '' ? (string) $query['code'] : null);
        $back = (string) ($query['back'] ?? '/');

        // Same-site paths only.
        return $this->responder->redirect(preg_match('#^/(?!/)[^\s\\\\]*$#', $back) === 1 ? $back : '/');
    }
}
