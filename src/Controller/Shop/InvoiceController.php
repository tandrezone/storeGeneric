<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Repository\OrderRepository;
use App\Service\InvoiceService;
use App\Service\OrderLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Invoice / credit note PDF for an order: GET /order/invoice?order=…&key=…[&type=credit_note].
 * Same access rule as the order pages (signed key, or the session that placed the order).
 */
final class InvoiceController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly OrderRepository $orders,
        private readonly OrderLinks $links,
        private readonly InvoiceService $invoices,
    ) {
    }

    public function download(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $number = strtoupper(trim((string) ($query['order'] ?? '')));
        $key = (string) ($query['key'] ?? '');
        if ($number === '' || !$this->links->canView($number, $key)) {
            throw HttpException::notFound('Order not found.');
        }
        $order = $this->orders->findByNumber($number) ?? throw HttpException::notFound('Order not found.');
        $kind = ($query['type'] ?? '') === 'credit_note' ? InvoiceService::CREDIT_NOTE : InvoiceService::INVOICE;

        try {
            $pdf = $this->invoices->pdf($order, $kind);
        } catch (RuntimeException) {
            throw HttpException::notFound('No document for this order yet.');
        }

        return $this->responder->downloadContent($pdf['content'], $pdf['filename'], 'application/pdf');
    }
}
