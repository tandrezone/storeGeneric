<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database;
use App\Infrastructure\Mailer;
use App\Repository\SettingRepository;
use App\Service\MailQueue;
use App\Service\OrderService;
use App\Support\Config;
use App\Support\Paths;
use Psr\Log\NullLogger;
use Tests\IntegrationTestCase;

final class EmailAttachmentTest extends IntegrationTestCase
{
    private function lastMail(): array
    {
        return $this->pdo()->query('SELECT * FROM mail_queue ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function testFailedMailKeepsItsAttachmentForTheRetry(): void
    {
        $this->pdo()->exec('DELETE FROM mail_queue');
        $broken = new MailQueue($this->get(Database::class), new Mailer(Config::fromArray(['MAIL_TRANSPORT' => 'smtp']), $this->get(Paths::class)), new NullLogger());
        $pdf = "%PDF-1.4\n\x00\xFF binary \xE2\xE3\n%%EOF";
        $broken->deliver('a@example.com', 'Invoice', '<p>x</p>', 'x', null, [['filename' => 'INV-1.pdf', 'type' => 'application/pdf', 'content' => $pdf]]);

        $row = $this->lastMail();
        $files = json_decode($row['attachments'], true);
        $this->assertSame('INV-1.pdf', $files[0]['filename']);
        $this->assertSame($pdf, base64_decode($files[0]['data']), 'binary survives the round trip');

        $good = new MailQueue($this->get(Database::class), new Mailer(Config::fromArray(['MAIL_TRANSPORT' => 'log', 'STORE_EMAIL' => 'store@example.com']), $this->get(Paths::class)), new NullLogger());
        $this->pdo()->exec('UPDATE mail_queue SET next_attempt_at = NOW() - INTERVAL 1 MINUTE');
        $this->assertSame(1, $good->processDue()['sent']);
    }

    public function testMimeStructureWithAnAttachment(): void
    {
        $mailer = new Mailer(Config::fromArray(['MAIL_TRANSPORT' => 'log', 'STORE_EMAIL' => 'store@example.com']), $this->get(Paths::class));
        [, , $headers, $body] = $mailer->compose('buyer@example.com', 'Your invoice', '<p>Hi</p>', 'Hi', null, [['filename' => 'my invoice (1).pdf', 'type' => 'application/pdf', 'content' => 'PDFDATA']]);
        $eml = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        $this->assertStringContainsString('multipart/mixed', $eml);
        $this->assertStringContainsString('multipart/alternative', $eml);
        $this->assertStringContainsString('filename="my_invoice__1_.pdf"', $eml);
        $this->assertStringContainsString(chunk_split(base64_encode('PDFDATA'), 76, "\r\n"), $eml);
        $this->assertStringContainsString('Content-Type: text/html', $eml);
    }

    public function testPaidOrderEmailCarriesTheInvoiceAndSettingTurnsItOff(): void
    {
        $this->pdo()->exec('DELETE FROM mail_queue');
        $settings = $this->get(SettingRepository::class);
        $ids = $this->makeProduct($this->sku(), 10.0, 5);

        // Mail goes through the log transport in tests (delivered, not queued): read what the notifier hands over.
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $this->get(OrderService::class)->markPaid($order['id']);
        $log = (string) file_get_contents(dirname(__DIR__, 2) . '/var/log/mail-' . date('Y-m-d') . '.log');
        $this->assertStringContainsString('[attachments: INV-', $log);

        $settings->set('invoice_attach', '0');
        $second = $this->placeOrder($ids['variant'], 1, 10.0);
        $before = strlen((string) file_get_contents(dirname(__DIR__, 2) . '/var/log/mail-' . date('Y-m-d') . '.log'));
        $this->get(OrderService::class)->markPaid($second['id']);
        $added = substr((string) file_get_contents(dirname(__DIR__, 2) . '/var/log/mail-' . date('Y-m-d') . '.log'), $before);
        $settings->set('invoice_attach', null);
        $this->assertStringNotContainsString('[attachments:', $added);
    }
}
