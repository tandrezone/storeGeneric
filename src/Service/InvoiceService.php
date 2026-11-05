<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\LocaleFormat;
use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\SettingRepository;
use App\Support\Money;
use App\Support\SimplePdf;
use PDOException;
use RuntimeException;

/**
 * Invoices and credit notes as PDF. A document gets its number the first time
 * it is requested (gap-free per kind and year; see migration 034) and the PDF
 * is rebuilt from the order each time. An invoice exists for orders that were
 * paid; a credit note for refunded ones. The seller block comes from
 * Admin → Settings → Invoices.
 */
final class InvoiceService
{
    public const INVOICE = 'invoice';
    public const CREDIT_NOTE = 'credit_note';

    private const PREFIX = [self::INVOICE => 'INV', self::CREDIT_NOTE => 'CN'];

    public function __construct(
        private readonly Database $db,
        private readonly StoreSettings $store,
        private readonly SettingRepository $settings,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, mixed> $order */
    public function canInvoice(array $order): bool
    {
        return in_array($order['payment_status'], ['paid', 'refunded'], true);
    }

    /** @param array<string, mixed> $order */
    public function canCreditNote(array $order): bool
    {
        return $order['status'] === 'refunded' || $order['payment_status'] === 'refunded';
    }

    /** Whether payment / refund emails carry the PDF (Settings → Invoices; on by default). */
    public function attachEnabled(): bool
    {
        return $this->settings->get('invoice_attach', '1') === '1';
    }

    /**
     * The PDF as an email attachment list: [] when attaching is off, the order isn't eligible, or building it fails
     * (an email must never be lost because of its attachment).
     *
     * @param array<string, mixed> $order order with `items`
     * @return list<array{filename: string, type: string, content: string}>
     */
    public function attachment(array $order, string $kind = self::INVOICE): array
    {
        if (!$this->attachEnabled() || !($kind === self::INVOICE ? $this->canInvoice($order) : $this->canCreditNote($order))) {
            return [];
        }
        try {
            $pdf = $this->pdf($order, $kind);

            return [['filename' => $pdf['filename'], 'type' => 'application/pdf', 'content' => $pdf['content']]];
        } catch (\Throwable) {
            return [];
        }
    }

    /** The seller details printed on documents (Admin → Settings → Invoices). @return array{name: string, address: string, vat_id: string, note: string} */
    public function seller(): array
    {
        return [
            'name'    => (string) ($this->settings->get('company_name') ?? $this->store->name()),
            'address' => (string) ($this->settings->get('company_address') ?? ''),
            'vat_id'  => (string) ($this->settings->get('company_vat_id') ?? ''),
            'note'    => (string) ($this->settings->get('invoice_note') ?? ''),
        ];
    }

    /** @return array<string, mixed>|null the issued document row, without issuing one */
    public function find(int $orderId, string $kind): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE order_id = :o AND kind = :k');
        $stmt->execute(['o' => $orderId, 'k' => $kind]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Issues the document if it doesn't exist yet.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed> the invoices row
     */
    public function issue(array $order, string $kind = self::INVOICE): array
    {
        $allowed = $kind === self::INVOICE ? $this->canInvoice($order) : $this->canCreditNote($order);
        if (!$allowed) {
            throw new RuntimeException($this->translator->trans($kind === self::INVOICE ? 'An invoice is available once the order is paid.' : 'A credit note is available once the order is refunded.'));
        }
        $existing = $this->find((int) $order['id'], $kind);
        if ($existing !== null) {
            return $existing;
        }

        $pdo = $this->db->pdo();
        $year = (int) date('Y');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $seq = (int) $pdo->query("SELECT COALESCE(MAX(seq), 0) + 1 FROM invoices WHERE kind = " . $pdo->quote($kind) . " AND year = {$year}")->fetchColumn();
                $pdo->prepare('INSERT INTO invoices (order_id, kind, year, seq, number, issued_at) VALUES (:o, :k, :y, :s, :n, NOW())')->execute([
                    'o' => $order['id'], 'k' => $kind, 'y' => $year, 's' => $seq, 'n' => self::number($kind, $year, $seq),
                ]);

                return $this->find((int) $order['id'], $kind) ?? throw new RuntimeException('Invoice vanished.');
            } catch (PDOException $e) {
                // Two requests took the same number (or the same order): look again.
                $existing = $this->find((int) $order['id'], $kind);
                if ($existing !== null) {
                    return $existing;
                }
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Could not number the document.');
    }

    public static function number(string $kind, int $year, int $seq): string
    {
        return sprintf('%s-%d-%05d', self::PREFIX[$kind], $year, $seq);
    }

    /**
     * The PDF for an order's invoice or credit note (issuing it first).
     *
     * @param array<string, mixed> $order order with `items`
     * @return array{filename: string, content: string, number: string}
     */
    public function pdf(array $order, string $kind = self::INVOICE): array
    {
        $doc = $this->issue($order, $kind);
        $locale = ($order['locale'] ?? null) ?: $this->store->language();
        $content = $this->translator->withLocale($locale, fn (): string => $this->render($order, $doc, $kind, $locale));

        return ['filename' => $doc['number'] . '.pdf', 'content' => $content, 'number' => (string) $doc['number']];
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $doc */
    private function render(array $order, array $doc, string $kind, string $locale): string
    {
        $t = fn (string $m, array $p = []): string => $this->translator->trans($m, $p);
        $currency = $this->store->currency();
        $intl = $this->translator->intlLocale($locale);
        // ICU uses narrow / no-break spaces that Windows-1252 can't print: plain spaces instead.
        $money = static fn (float $v): string => str_replace(["\u{202F}", "\u{00A0}"], ' ', Money::format($v, $currency, $intl));
        $sign = $kind === self::CREDIT_NOTE ? -1.0 : 1.0;
        $seller = $this->seller();

        $pdf = new SimplePdf();
        $left = 50.0;
        $right = SimplePdf::WIDTH - 50.0;
        $grey = [0.4, 0.4, 0.4];
        $y = 60.0;

        // Seller (left) and document title (right)
        $pdf->text($left, $y, $seller['name'], 16, true);
        $sy = $y + 18;
        foreach (array_filter(array_map('trim', explode("\n", $seller['address']))) as $line) {
            $pdf->text($left, $sy, $line, 9, false, 'left', $grey);
            $sy += 12;
        }
        if ($seller['vat_id'] !== '') {
            $pdf->text($left, $sy, $t('VAT ID: {id}', ['id' => $seller['vat_id']]), 9, false, 'left', $grey);
            $sy += 12;
        }
        $pdf->text($right, $y, $t($kind === self::CREDIT_NOTE ? 'Credit note' : 'Invoice'), 20, true, 'right');
        $pdf->text($right, $y + 20, $t('No. {number}', ['number' => $doc['number']]), 10, false, 'right');
        $pdf->text($right, $y + 33, $t('Date: {date}', ['date' => LocaleFormat::date((string) $doc['issued_at'], $intl, 'medium')]), 10, false, 'right');
        $pdf->text($right, $y + 46, $t('Order: {number}', ['number' => $order['order_number']]), 10, false, 'right');
        if ($kind === self::CREDIT_NOTE && ($invoice = $this->find((int) $order['id'], self::INVOICE)) !== null) {
            $pdf->text($right, $y + 59, $t('Refund for invoice {number}', ['number' => $invoice['number']]), 9, false, 'right', $grey);
        }

        // Customer
        $y = max($sy, $y + 75) + 20;
        $pdf->text($left, $y, $t('Bill to'), 9, true, 'left', $grey);
        $y += 14;
        $address = [
            $order['ship_name'], $order['ship_address1'], $order['ship_address2'],
            trim($order['ship_postal_code'] . ' ' . $order['ship_city'] . ($order['ship_state'] ? ', ' . $order['ship_state'] : '')),
            $order['ship_country'], $order['email'],
        ];
        foreach (array_filter(array_map(static fn ($l) => trim((string) $l), $address)) as $line) {
            $pdf->text($left, $y, $line, 10);
            $y += 13;
        }
        if (!empty($order['vat_id'])) {
            $pdf->text($left, $y, $t('VAT ID: {id}', ['id' => $order['vat_id']]), 10);
            $y += 13;
        }

        // Items table
        $y += 18;
        $colQty = $right - 170;
        $colUnit = $right - 90;
        $header = function (float $at) use ($pdf, $left, $right, $colQty, $colUnit, $t): float {
            $pdf->text($left, $at, $t('Description'), 9, true);
            $pdf->text($colQty, $at, $t('Qty'), 9, true, 'right');
            $pdf->text($colUnit, $at, $t('Unit price'), 9, true, 'right');
            $pdf->text($right, $at, $t('Amount'), 9, true, 'right');
            $pdf->line($left, $at + 5, $right, $at + 5);

            return $at + 20;
        };
        $y = $header($y);
        $limit = SimplePdf::HEIGHT - 110;
        $row = function (string $description, ?string $qty, ?string $unit, string $amount) use (&$y, &$pdf, $left, $right, $colQty, $colUnit, $limit, $header): void {
            $lines = $pdf->wrap($description, $colQty - $left - 50, 10);
            if ($y + count($lines) * 13 > $limit) {
                $pdf->addPage();
                $y = $header(60.0);
            }
            foreach ($lines as $i => $line) {
                $pdf->text($left, $y + $i * 13, $line, 10);
            }
            if ($qty !== null) {
                $pdf->text($colQty, $y, $qty, 10, false, 'right');
            }
            if ($unit !== null) {
                $pdf->text($colUnit, $y, $unit, 10, false, 'right');
            }
            $pdf->text($right, $y, $amount, 10, false, 'right');
            $y += count($lines) * 13 + 4;
        };

        foreach ($order['items'] as $item) {
            $variant = trim((string) $item['label'] . ' ' . (string) $item['unit']);
            $row($item['product_name'] . ($variant !== '' ? ' — ' . $variant : ''), (string) $item['quantity'], $money((float) $item['unit_price']), $money($sign * (float) $item['line_total']));
        }
        $row($t('Shipping ({method})', ['method' => $order['shipping_label'] ?: $order['shipping_method']]), null, null, $money($sign * (float) $order['shipping_cost']));
        if ((float) $order['discount_amount'] > 0) {
            $row($order['coupon_code'] ? $t('Discount ({code})', ['code' => $order['coupon_code']]) : $t('Discount'), null, null, $money(-$sign * (float) $order['discount_amount']));
        }

        // Totals
        if ($y + 80 > $limit) {
            $pdf->addPage();
            $y = 60.0;
        }
        $pdf->line($colQty - 40, $y, $right, $y);
        $y += 16;
        $tax = (float) $order['tax_amount'];
        $rate = rtrim(rtrim(number_format((float) $order['tax_rate'], 2, '.', ''), '0'), '.');
        $net = round((float) $order['total'] + (float) ($order['gift_card_amount'] ?? 0) - $tax, 2);
        if ($tax > 0) {
            $pdf->text($colUnit, $y, $t('Net amount'), 10, false, 'right');
            $pdf->text($right, $y, $money($sign * $net), 10, false, 'right');
            $y += 14;
            $pdf->text($colUnit, $y, $t('VAT ({rate}%)', ['rate' => $rate]), 10, false, 'right');
            $pdf->text($right, $y, $money($sign * $tax), 10, false, 'right');
            $y += 14;
        }
        $gift = (float) ($order['gift_card_amount'] ?? 0);
        $pdf->text($colUnit, $y, $t($kind === self::CREDIT_NOTE ? 'Total refunded' : 'Total'), 11, true, 'right');
        $pdf->text($right, $y, $money($sign * ((float) $order['total'] + $gift)), 11, true, 'right');
        $y += 16;
        if ($gift > 0) {
            // orders.total is what was left to pay after the gift card.
            $pdf->text($colUnit, $y, $t('Gift card ({code})', ['code' => (string) $order['gift_card_code']]), 10, false, 'right');
            $pdf->text($right, $y, $money(-$sign * $gift), 10, false, 'right');
            $y += 14;
            $pdf->text($colUnit, $y, $t($kind === self::CREDIT_NOTE ? 'Refunded to the original payment' : 'Amount paid'), 10, true, 'right');
            $pdf->text($right, $y, $money($sign * (float) $order['total']), 10, true, 'right');
            $y += 16;
        }
        if (!empty($order['vat_reverse_charge'])) {
            $y += 14;
            $pdf->text($right, $y, $t('VAT reverse charge: no VAT charged; the customer accounts for VAT.'), 9, true, 'right');
        }
        $pdf->text($right, $y, $t($tax > 0 && $order['prices_include_tax'] ? 'Prices include VAT.' : ($tax > 0 ? 'VAT added to prices.' : 'No VAT charged.')), 8, false, 'right', $grey);

        // Footer on the last page
        if ($seller['note'] !== '') {
            $fy = SimplePdf::HEIGHT - 70;
            foreach ($pdf->wrap($seller['note'], $right - $left, 8) as $i => $line) {
                $pdf->text($left, $fy + $i * 10, $line, 8, false, 'left', $grey);
            }
        }

        return $pdf->output();
    }
}
