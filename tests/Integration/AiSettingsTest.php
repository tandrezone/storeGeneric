<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\GeminiClient;
use App\Repository\SettingRepository;
use App\Service\AiSettings;
use App\Support\Config;
use ReflectionProperty;
use RuntimeException;
use Tests\IntegrationTestCase;

final class AiSettingsTest extends IntegrationTestCase
{
    public function tearDown(): void
    {
        // Saved keys would leak into later tests that share this database.
        $this->pdo()->exec("DELETE FROM settings WHERE name LIKE 'ai.%'");
        parent::tearDown();
    }

    public function testKeyIsEncryptedKeptWhenEmptyAndWinsOverEnv(): void
    {
        $ai = $this->get(AiSettings::class);
        $env = Config::fromArray(['GEMINI_API_KEY' => 'env-key', 'GEMINI_MODEL' => 'env-model']);
        $this->assertSame('env-key', $ai->config($env)->get('GEMINI_API_KEY'));

        $ai->save(['gemini_api_key' => 'AIzaSaved_key-1234', 'models' => ['GEMINI_MODEL' => 'gemini-2.5-pro', 'GEMINI_IMAGE_MODEL' => '']]);
        $this->assertStringNotContainsString('AIzaSaved', (string) $this->get(SettingRepository::class)->get('ai.GEMINI_API_KEY'));
        $this->assertSame('AIzaSaved_key-1234', $ai->config($env)->get('GEMINI_API_KEY'));
        $this->assertSame('gemini-2.5-pro', $ai->config($env)->get('GEMINI_MODEL'));

        $ai->save(['gemini_api_key' => '', 'models' => []]);
        $this->assertSame('AIzaSaved_key-1234', $ai->config($env)->get('GEMINI_API_KEY'));
        $this->assertSame('…1234', $ai->overview($env)['key_hint']);

        $ai->save(['clear_key' => '1']);
        $this->assertFalse($ai->hasKey($env));
    }

    public function testGeminiClientFromTheContainerUsesTheSavedKey(): void
    {
        $this->get(AiSettings::class)->save(['gemini_api_key' => 'AIzaContainer', 'models' => []]);
        $client = $this->get(GeminiClient::class);
        $config = (new ReflectionProperty($client, 'config'))->getValue($client);
        $this->assertSame('AIzaContainer', $config->get('GEMINI_API_KEY'));
    }

    public function testBadKeysAndModelNamesAreRejected(): void
    {
        $ai = $this->get(AiSettings::class);
        $this->assertThrows(RuntimeException::class, fn () => $ai->save(['gemini_api_key' => 'has a space']));
        $this->assertThrows(RuntimeException::class, fn () => $ai->save(['models' => ['GEMINI_MODEL' => '../../evil?x=1']]));
    }
}
