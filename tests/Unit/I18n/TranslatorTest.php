<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\LocaleFormat;
use App\I18n\Translator;
use App\Support\Money;
use Tests\TestCase;

/** Translator lookups, fallbacks, placeholders and plural forms (fixture catalogs in a temp folder). */
final class TranslatorTest extends TestCase
{
    private string $dir;
    private Translator $translator;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/store-i18n-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/en.php', '<?php return ' . var_export([
            '@name'                 => 'English',
            'Cart'                  => 'Cart',
            'Only in English'       => 'Only in English',
            'Hello, {name}!'        => 'Hello, {name}!',
            '{count} item'          => ['one' => '{count} item', 'other' => '{count} items'],
        ], true) . ';');
        file_put_contents($this->dir . '/pt.php', '<?php return ' . var_export([
            '@name'                 => 'Português',
            '@html_lang'            => 'pt-PT',
            '@intl'                 => 'pt_PT',
            'Cart'                  => 'Carrinho',
            'Only in English'       => '',
            'Hello, {name}!'        => 'Olá, {name}!',
            '{count} item'          => ['one' => '{count} artigo', 'other' => '{count} artigos'],
        ], true) . ';');
        file_put_contents($this->dir . '/js-keys.php', '<?php return [];'); // not a catalog
        $this->translator = new Translator($this->dir);
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testEnglishIsTheDefault(): void
    {
        $this->assertSame('en', $this->translator->locale());
        $this->assertSame('Cart', $this->translator->trans('Cart'));
    }

    public function testTranslatesInTheActiveLocale(): void
    {
        $this->translator->setLocale('pt');
        $this->assertSame('Carrinho', $this->translator->trans('Cart'));
        $this->assertSame('pt-PT', $this->translator->htmlLang());
        $this->assertSame('pt_PT', $this->translator->intlLocale());
    }

    public function testMissingTranslationFallsBackToEnglishThenToTheKey(): void
    {
        $this->translator->setLocale('pt');
        $this->assertSame('Only in English', $this->translator->trans('Only in English'), 'empty translation → English');
        $this->assertSame('Not in any catalog', $this->translator->trans('Not in any catalog'), 'unknown key → the key');
        $this->assertSame('Hi Ana', $this->translator->trans('Hi {name}', ['name' => 'Ana']), 'placeholders work on unknown keys too');
    }

    public function testPlaceholders(): void
    {
        $this->translator->setLocale('pt');
        $this->assertSame('Olá, Ana!', $this->translator->trans('Hello, {name}!', ['name' => 'Ana']));
        $this->assertSame('Olá, Rui!', $this->translator->trans('Hello, {name}!', ['{name}' => 'Rui']), 'braces in the name are fine');
        $this->assertSame('Olá, {name}!', $this->translator->trans('Hello, {name}!'), 'unfilled placeholders are left alone');
    }

    public function testPluralForms(): void
    {
        $this->assertSame('1 item', $this->translator->transPlural('{count} item', 1));
        $this->assertSame('3 items', $this->translator->transPlural('{count} item', 3));
        $this->assertSame('0 items', $this->translator->transPlural('{count} item', 0));

        $this->translator->setLocale('pt');
        $this->assertSame('1 artigo', $this->translator->transPlural('{count} item', 1));
        $this->assertSame('2 artigos', $this->translator->transPlural('{count} item', 2));
        $this->assertSame('0 artigos', $this->translator->transPlural('{count} item', 0), 'pt-PT: zero is plural');
        $this->assertSame('5 things', $this->translator->transPlural('{count} things', 5), 'unknown plural key → the key');
    }

    public function testUnsupportedLocalesFallBackToEnglish(): void
    {
        $this->translator->setLocale('de');
        $this->assertSame('en', $this->translator->locale());
        $this->assertSame('pt', $this->translator->normalize('pt-PT'));
        $this->assertSame('pt', $this->translator->normalize('PT_pt'));
        $this->assertNull($this->translator->normalize('../etc'));
        $this->assertNull($this->translator->normalize(''));
        $this->assertSame(['en' => 'English', 'pt' => 'Português'], $this->translator->languages(), 'js-keys.php is not a language');
    }

    public function testWithLocaleRestoresThePreviousOne(): void
    {
        $this->translator->setLocale('pt');
        $text = $this->translator->withLocale('en', fn () => $this->translator->trans('Cart'));
        $this->assertSame('Cart', $text);
        $this->assertSame('pt', $this->translator->locale());
        $this->assertSame('Carrinho', $this->translator->withLocale(null, fn () => $this->translator->trans('Cart')), 'null keeps the current locale');
        $this->assertSame('Carrinho', $this->translator->trans('Cart', [], 'pt'), 'explicit locale argument');
    }

    public function testExportForScripts(): void
    {
        $this->translator->setLocale('pt');
        $this->assertSame(
            ['Cart' => 'Carrinho', '{count} item' => ['one' => '{count} artigo', 'other' => '{count} artigos'], 'Unknown' => 'Unknown'],
            $this->translator->export(['Cart', '{count} item', 'Unknown'])
        );
    }

    public function testLocaleFormats(): void
    {
        $this->assertSame('12,50 €', str_replace(["\u{a0}", "\u{202f}"], ' ', Money::format(12.5, 'EUR', 'pt_PT')));
        $this->assertSame('€12.50', Money::format(12.5, 'EUR', 'en'));
        $this->assertSame('1 234,5', str_replace(["\u{a0}", "\u{202f}"], ' ', LocaleFormat::number(1234.5, 'pt_PT', 1)));
        $this->assertSame('1,234.50', LocaleFormat::number(1234.5, 'en', 2));
        $this->assertSame('07/10/2026', LocaleFormat::date('2026-10-07 12:00:00', 'pt_PT', 'short'));
        $this->assertSame('', LocaleFormat::date(null, 'pt_PT'));
    }
}
