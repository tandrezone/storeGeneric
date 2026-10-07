<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use Tests\TestCase;

/** Every catalog in translations/ has the same keys as en.php, the same placeholders and valid plural entries. */
final class CatalogTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../translations';

    /** @return array<string, string|array<string, string>> */
    private function load(string $locale): array
    {
        $catalog = require self::DIR . '/' . $locale . '.php';
        $this->assertTrue(is_array($catalog), $locale . '.php must return an array');

        return $catalog;
    }

    public function testAllCatalogsHaveTheSameKeysAsEnglish(): void
    {
        $en = $this->load('en');
        $translator = new Translator(self::DIR);
        $this->assertTrue(count($translator->available()) >= 2, 'en and pt are installed');
        foreach ($translator->available() as $locale) {
            $catalog = $this->load($locale);
            $missing = array_keys(array_diff_key($en, $catalog));
            $extra = array_keys(array_diff_key($catalog, $en));
            $this->assertSame([], $missing, "$locale.php is missing keys");
            $this->assertSame([], $extra, "$locale.php has keys that en.php doesn't");
        }
    }

    public function testEnglishValuesMatchTheirKeys(): void
    {
        foreach ($this->load('en') as $key => $value) {
            if (is_string($value) && $key[0] !== '@') {
                $this->assertSame($key, $value, 'en.php: the text of a simple message is its key');
            }
        }
    }

    public function testPlaceholdersAndPluralForms(): void
    {
        $en = $this->load('en');
        foreach ((new Translator(self::DIR))->available() as $locale) {
            foreach ($this->load($locale) as $key => $value) {
                if ($key[0] === '@') {
                    $this->assertTrue(is_string($value), "$locale.php: $key is text");
                    continue;
                }
                if (is_array($en[$key] ?? null)) {
                    $this->assertTrue(is_array($value) && isset($value['one'], $value['other']), "$locale.php: '$key' needs one/other forms");
                } else {
                    $this->assertTrue(is_string($value) && $value !== '', "$locale.php: '$key' needs a translation");
                }
                $this->assertSame(self::placeholders($en[$key] ?? ''), self::placeholders($value), "$locale.php: placeholders of '$key'");
            }
        }
    }

    public function testScriptKeysExistInTheCatalog(): void
    {
        $en = $this->load('en');
        foreach (require self::DIR . '/js-keys.php' as $key) {
            $this->assertTrue(isset($en[$key]), "js-keys.php: '$key' is not in en.php");
        }
    }

    /**
     * @param string|array<string, string> $value
     * @return list<string>
     */
    private static function placeholders(string|array $value): array
    {
        preg_match_all('/\{\w+\}/', is_array($value) ? implode(' ', $value) : $value, $matches);
        $names = array_values(array_unique($matches[0]));
        sort($names);

        return $names;
    }
}
