<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\PageRepository;
use App\Security\HtmlSanitizer;
use Tests\HttpTestCase;

final class PagesHttpTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('DELETE FROM pages');
    }

    public function tearDown(): void
    {
        $this->pdo()->exec('DELETE FROM pages');
        parent::tearDown();
    }

    public function testStaffCreatesAPageThatShowsInFooterAndSitemap(): void
    {
        $this->loginAdmin('manager');
        $this->post('/admin/pages', [
            'action' => 'save', 'slug' => 'shipping-faq', 'locale' => '', 'title' => 'Shipping FAQ',
            'body' => '<h2>Delivery</h2><p>We ship <b>daily</b>.</p><script>alert(1)</script>', 'is_published' => '1', 'in_footer' => '1',
        ]);

        $page = $this->html($this->get_('/p/shipping-faq'));
        $this->assertStringContainsString('<h2>Delivery</h2>', $page);
        $this->assertStringContainsString('We ship <b>daily</b>', $page);
        $this->assertStringNotContainsString('alert(1)', $page, 'sanitized');
        $this->assertStringContainsString('href="/p/shipping-faq"', $this->html($this->get_('/about')), 'footer link');
        $this->assertStringContainsString('/p/shipping-faq', $this->html($this->get_('/sitemap.xml')));
    }

    public function testDraftsAreHiddenAndUnknownPagesAre404(): void
    {
        $repo = $this->get(PageRepository::class);
        $repo->create(['slug' => 'secret', 'locale' => '', 'title' => 'Secret', 'body' => '<p>x</p>', 'is_published' => 0, 'in_footer' => 1, 'sort_order' => 0]);
        $this->assertStatus(404, $this->get_('/p/secret'));
        $this->assertStatus(404, $this->get_('/p/nothing-here'));
        $this->assertStringNotContainsString('/p/secret', $this->html($this->get_('/about')));
    }

    public function testBuiltInPageCanBeReplacedAndRestored(): void
    {
        $this->loginAdmin('manager');
        $this->assertStringNotContainsString('Our own story', $this->html($this->get_('/about')));

        $this->post('/admin/pages', ['action' => 'save', 'slug' => 'about', 'locale' => '', 'title' => 'About us', 'body' => '<p>Our own story</p>', 'is_published' => '1']);
        $this->assertStringContainsString('Our own story', $this->html($this->get_('/about')));

        $id = (int) $this->pdo()->query("SELECT id FROM pages WHERE slug = 'about'")->fetchColumn();
        $this->post('/admin/pages', ['action' => 'delete', 'id' => $id]);
        $this->assertStringNotContainsString('Our own story', $this->html($this->get_('/about')));
    }

    public function testLanguageSpecificPageWinsOverTheGeneralOne(): void
    {
        $repo = $this->get(PageRepository::class);
        $repo->create(['slug' => 'terms', 'locale' => '', 'title' => 'General terms', 'body' => '<p>General body</p>', 'is_published' => 1, 'in_footer' => 0, 'sort_order' => 0]);
        $repo->create(['slug' => 'terms', 'locale' => 'pt', 'title' => 'Termos', 'body' => '<p>Corpo em português</p>', 'is_published' => 1, 'in_footer' => 0, 'sort_order' => 0]);
        $this->assertStringContainsString('General body', $this->html($this->get_('/terms', ['lang' => 'en'])));
        $this->assertStringContainsString('Corpo em português', $this->html($this->get_('/terms', ['lang' => 'pt'])));
    }

    public function testValidation(): void
    {
        $this->loginAdmin('manager');
        $this->assertStatus(422, $this->post('/admin/pages', ['action' => 'save', 'slug' => 'Bad Slug!', 'locale' => '', 'title' => 'X', 'body' => '<p>x</p>']));
        $this->assertStatus(422, $this->post('/admin/pages', ['action' => 'save', 'slug' => 'ok-slug', 'locale' => '', 'title' => '', 'body' => '<p>x</p>']));
        $this->assertStatus(422, $this->post('/admin/pages', ['action' => 'save', 'slug' => 'ok-slug', 'locale' => '', 'title' => 'T', 'body' => '<script>x</script>']));
        $this->post('/admin/pages', ['action' => 'save', 'slug' => 'dup', 'locale' => '', 'title' => 'T', 'body' => '<p>x</p>']);
        $this->assertStatus(422, $this->post('/admin/pages', ['action' => 'save', 'slug' => 'dup', 'locale' => '', 'title' => 'T2', 'body' => '<p>y</p>']));
        $this->assertStatus(200, $this->get_('/admin/pages'));
    }

    public function testSanitizerKeepsPageHeadingsOnlyForPages(): void
    {
        $s = new HtmlSanitizer();
        $this->assertSame('<h2>Hi</h2><p>x</p>', $s->cleanPage('<h2 class="a" onclick="x()">Hi</h2><p>x</p>'));
        $this->assertSame('Hi<p>x</p>', $s->clean('<h2>Hi</h2><p>x</p>'), 'product descriptions keep their small allowlist');
    }
}
