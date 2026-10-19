<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\I18n\Translator;
use App\Service\PromptLibrary;
use App\Support\Paths;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/** Prompt files uploaded in Admin → Settings: safe names, text only, listed and deleted by name. */
final class PromptLibraryTest extends TestCase
{
    private string $root;
    private PromptLibrary $prompts;

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/store-prompts-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var', 0777, true);
        $this->prompts = new PromptLibrary(new Paths($this->root), new Translator(dirname(__DIR__, 3) . '/translations'));
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/var/prompts/*') ?: []);
        @rmdir($this->root . '/var/prompts');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    public function testCleanNameKeepsOnlySafeCharactersAndAllowedTypes(): void
    {
        $this->assertSame('My-prompt.md', PromptLibrary::cleanName('My prompt!.MD'));
        $this->assertSame('passwd.txt', PromptLibrary::cleanName('../../etc/passwd.txt'));
        $this->assertSame('prompt.md', PromptLibrary::cleanName('…….md'));
        $this->assertNull(PromptLibrary::cleanName('shell.php'));
        $this->assertNull(PromptLibrary::cleanName('notes.md.php'));
    }

    public function testStoreListReadAndDelete(): void
    {
        $name = $this->prompts->store($this->upload('Plant profile.md', "# Prompt\nDescribe {plant}."));
        $this->assertSame('Plant-profile.md', $name);
        $this->assertSame("# Prompt\nDescribe {plant}.", $this->prompts->contents($name));
        $this->assertSame(['Plant-profile.md'], array_column($this->prompts->all(), 'name'));

        $this->prompts->store($this->upload('Plant profile.md', 'v2'));
        $this->assertSame('v2', $this->prompts->contents($name), 'same name replaces the file');
        $this->assertCount(1, $this->prompts->all());

        $this->prompts->delete($name);
        $this->assertSame([], $this->prompts->all());
        $this->assertThrows(RuntimeException::class, fn () => $this->prompts->delete($name));
    }

    public function testRejectsBinaryOversizedAndOtherTypes(): void
    {
        $this->assertThrows(RuntimeException::class, fn () => $this->prompts->store($this->upload('image.png', 'x')));
        $this->assertThrows(RuntimeException::class, fn () => $this->prompts->store($this->upload('bin.txt', "a\0b")));
        $this->assertThrows(RuntimeException::class, fn () => $this->prompts->store($this->upload('big.md', str_repeat('a', PromptLibrary::MAX_BYTES + 1))));
        $this->assertSame([], $this->prompts->all());
    }

    public function testPathIgnoresTraversal(): void
    {
        $this->assertNull($this->prompts->path('../.env'));
        $this->assertNull($this->prompts->path('.htaccess'));
    }

    public function testOverrideIsNullWithoutAFileOrWhenBlank(): void
    {
        $this->assertNull($this->prompts->override(PromptLibrary::MAGIC_EDIT, ['instruction' => 'x']));
        $this->prompts->store($this->upload('magic-edit.md', "  \n"));
        $this->assertNull($this->prompts->override(PromptLibrary::MAGIC_EDIT, ['instruction' => 'x']));
        $this->assertNull($this->prompts->override('not-reserved', []));
    }

    public function testOverrideFillsPlaceholdersInOnePass(): void
    {
        $this->prompts->store($this->upload('translate-product.md', 'To {language} ({locale}): {name} / {unknown}'));
        $out = $this->prompts->override(PromptLibrary::TRANSLATE_PRODUCT, ['language' => 'Português', 'locale' => 'pt', 'name' => '{language}']);
        $this->assertSame('To Português (pt): {language} / {unknown}', $out);
    }

    public function testOverrideFallsBackToTxtFile(): void
    {
        $this->prompts->store($this->upload('magic-edit.txt', 'Do: {instruction}'));
        $this->assertSame('Do: shorten', $this->prompts->override(PromptLibrary::MAGIC_EDIT, ['instruction' => 'shorten']));
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        return new UploadedFile(Stream::create($contents), strlen($contents), UPLOAD_ERR_OK, $name);
    }
}
