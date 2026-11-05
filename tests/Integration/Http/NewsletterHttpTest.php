<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\NewsletterRepository;
use App\Repository\SettingRepository;
use Tests\HttpTestCase;

final class NewsletterHttpTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('DELETE FROM newsletter_subscribers');
        $this->pdo()->exec('DELETE FROM mail_queue');
        $this->get(SettingRepository::class)->set('newsletter_enabled', '1');
    }

    public function tearDown(): void
    {
        $this->get(SettingRepository::class)->set('newsletter_enabled', null);
        parent::tearDown();
    }

    private function row(string $email): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM newsletter_subscribers WHERE email = ?');
        $stmt->execute([$email]);

        return $stmt->fetch() ?: null;
    }

    public function testFormIsShownOnlyWhenSwitchedOn(): void
    {
        $this->assertStringContainsString('newsletter-email', $this->html($this->get_('/about')));
        $this->get(SettingRepository::class)->set('newsletter_enabled', '0');
        $this->assertStringNotContainsString('newsletter-email', $this->html($this->get_('/about')));
        $this->assertStatus(404, $this->post('/newsletter/subscribe', ['email' => 'a@example.com']));
    }

    public function testDoubleOptInThenUnsubscribe(): void
    {
        $this->post('/newsletter/subscribe', ['email' => 'Reader@Example.com', 'back' => '/about']);
        $row = $this->row('reader@example.com');
        $this->assertNotNull($row);
        $this->assertNull($row['confirmed_at'], 'not subscribed until the link is used');

        $log = (string) @file_get_contents($this->pathToMailLog());
        $this->assertStringContainsString('/newsletter/confirm?token=' . $row['token'], $log);

        $this->get_('/newsletter/confirm', ['token' => $row['token']]);
        $this->assertNotNull($this->row('reader@example.com')['confirmed_at']);
        $this->assertCount(1, $this->get(NewsletterRepository::class)->active());

        $this->get_('/newsletter/unsubscribe', ['token' => $row['token']]);
        $this->assertCount(0, $this->get(NewsletterRepository::class)->active());

        $this->get_('/newsletter/confirm', ['token' => 'bad-token']); // harmless
    }

    public function testHoneypotInvalidEmailAndResendThrottle(): void
    {
        $this->post('/newsletter/subscribe', ['email' => 'bot@example.com', 'website' => 'x']);
        $this->post('/newsletter/subscribe', ['email' => 'not-an-email']);
        $this->assertNull($this->row('bot@example.com'));

        $this->post('/newsletter/subscribe', ['email' => 'twice@example.com']);
        $first = $this->row('twice@example.com')['confirm_sent_at'];
        $this->pdo()->exec("UPDATE newsletter_subscribers SET confirm_sent_at = NOW() - INTERVAL 1 MINUTE WHERE email = 'twice@example.com'");
        $this->post('/newsletter/subscribe', ['email' => 'twice@example.com']);
        $this->assertSame(
            date('Y-m-d H:i', strtotime('-1 minute')),
            date('Y-m-d H:i', strtotime($this->row('twice@example.com')['confirm_sent_at'])),
            'no second confirmation email within 10 minutes'
        );
        $this->assertNotNull($first);
    }

    public function testCampaignGoesOnlyToConfirmedSubscribersWithTheirOwnUnsubscribeLink(): void
    {
        $repo = $this->get(NewsletterRepository::class);
        foreach (['yes1@example.com' => true, 'yes2@example.com' => true, 'pending@example.com' => false] as $email => $confirmed) {
            $token = $repo->create($email, 'en');
            if ($confirmed) {
                $repo->confirm((int) $repo->findByToken($token)['id']);
            }
        }
        $gone = $repo->create('gone@example.com', 'en');
        $repo->confirm((int) $repo->findByToken($gone)['id']);
        $repo->unsubscribe((int) $repo->findByToken($gone)['id']);

        $this->loginAdmin('manager');
        $this->post('/admin/newsletter', ['action' => 'send', 'subject' => 'Big news', 'message' => "First paragraph\n\nSecond one"]);

        $queued = $this->pdo()->query('SELECT to_email, subject, body_html FROM mail_queue ORDER BY to_email')->fetchAll();
        $this->assertSame(['yes1@example.com', 'yes2@example.com'], array_column($queued, 'to_email'));
        $this->assertStringContainsString('Big news', $queued[0]['subject']);
        $this->assertStringContainsString('/newsletter/unsubscribe?token=' . $repo->findByEmail('yes1@example.com')['token'], $queued[0]['body_html']);
        $this->assertStringNotContainsString($repo->findByEmail('yes2@example.com')['token'], $queued[0]['body_html'], 'each mail has only its own link');
        $this->assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM newsletter_campaigns WHERE subject = ' . $this->pdo()->quote('Big news'))->fetchColumn());
        $this->assertStringContainsString('Big news', $this->html($this->get_('/admin/newsletter')));
    }

    public function testExportAndEmptyCampaignRefused(): void
    {
        $repo = $this->get(NewsletterRepository::class);
        $token = $repo->create('csv@example.com', 'pt');
        $repo->confirm((int) $repo->findByToken($token)['id']);

        $this->loginAdmin('manager');
        $csv = $this->get_('/admin/newsletter/export');
        $this->assertStatus(200, $csv);
        $this->assertStringContainsString('csv@example.com', $this->html($csv));

        $this->assertStatus(422, $this->post('/admin/newsletter', ['action' => 'send', 'subject' => '', 'message' => '']));
    }

    public function testDeletingTheAccountRemovesTheSubscription(): void
    {
        $customer = $this->loginCustomer('correct horse battery');
        $repo = $this->get(NewsletterRepository::class);
        $token = $repo->create($customer['email'], 'en');
        $repo->confirm((int) $repo->findByToken($token)['id']);
        $this->post('/account/profile', ['action' => 'delete', 'confirm' => '1', 'current_password' => 'correct horse battery']);
        $this->assertNull($this->row($customer['email']));
    }

    private function pathToMailLog(): string
    {
        return dirname(__DIR__, 3) . '/var/log/mail-' . date('Y-m-d') . '.log';
    }
}
