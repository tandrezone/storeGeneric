<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\SettingRepository;
use Tests\HttpTestCase;

final class CookieNoticeHttpTest extends HttpTestCase
{
    private function set(?string $notice, ?string $snippet): void
    {
        $settings = $this->get(SettingRepository::class);
        $settings->set('cookie_notice', $notice);
        $settings->set('tracking_snippet', $snippet);
    }

    public function tearDown(): void
    {
        $this->set(null, null);
        parent::tearDown();
    }

    public function testInformationalNoticeByDefault(): void
    {
        $this->set(null, null);
        $page = $this->html($this->get_('/about'));
        $this->assertStringContainsString('id="cookie-notice"', $page);
        $this->assertStringNotContainsString('data-consent="declined"', $page);
        $this->assertStringNotContainsString('consent-snippet', $page);
    }

    public function testSnippetIsOnlyShippedInsideTheConsentTemplate(): void
    {
        $this->set('0', '<script>window.__t = 1;</script>');
        $page = $this->html($this->get_('/about'));
        $this->assertStringContainsString('<template id="consent-snippet"><script>window.__t = 1;</script></template>', $page, 'inert until accepted, and shown even if the toggle is off');
        $this->assertStringContainsString('data-consent="declined"', $page);
    }

    public function testCanBeSwitchedOff(): void
    {
        $this->set('0', null);
        $this->assertStringNotContainsString('id="cookie-notice"', $this->html($this->get_('/about')));
    }

    public function testOwnerSavesPrivacySettings(): void
    {
        $this->set(null, null);
        $this->loginAdmin('owner');
        $this->post('/admin/settings', ['action' => 'save_privacy', 'tracking_snippet' => '<script src="https://x.test/a.js"></script>']);
        $settings = $this->get(SettingRepository::class);
        $this->assertSame('0', $settings->get('cookie_notice'));
        $this->assertStringContainsString('x.test', (string) $settings->get('tracking_snippet'));
    }
}
