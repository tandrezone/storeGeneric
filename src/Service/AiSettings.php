<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Repository\SettingRepository;
use App\Support\Config;
use RuntimeException;

/**
 * The Gemini API key and model names, edited in Admin → Settings → AI so a
 * store owner never has to touch .env. Saved as "ai.<ENV_KEY>" in the
 * settings table; the key is encrypted (SettingsCipher). A value saved here
 * wins over .env, anything never saved here falls back to .env. GeminiClient
 * receives config() through the container (see config/container.php).
 */
final class AiSettings
{
    private const PREFIX = 'ai.';
    private const PURPOSE = 'ai-settings';
    public const KEY = 'GEMINI_API_KEY';
    /** Model setting => default (as GeminiClient uses it). */
    public const MODELS = [
        'GEMINI_MODEL'       => 'gemini-flash-latest',
        'GEMINI_IMAGE_MODEL' => 'gemini-2.5-flash-image',
    ];

    /** @var array<string, string>|null */
    private ?array $values = null;
    private bool $unreadable = false;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly SettingsCipher $cipher,
        private readonly Translator $translator,
    ) {
    }

    /** The .env configuration with the AI values saved in Settings laid over it. */
    public function config(Config $env): Config
    {
        return $env->withOverrides($this->values());
    }

    public function hasKey(Config $env): bool
    {
        return $this->config($env)->has(self::KEY);
    }

    /**
     * Saves the form: a new key only when one was typed (empty = keep), "clear_key" removes it;
     * model names as posted (empty = the default).
     *
     * @param array<string, mixed> $body
     */
    public function save(array $body): void
    {
        $writes = [];
        $key = trim((string) ($body['gemini_api_key'] ?? ''));
        if (!empty($body['clear_key'])) {
            $writes[self::KEY] = '';
        } elseif ($key !== '') {
            if (strlen($key) > 200 || !preg_match('/^[A-Za-z0-9_\-.]+$/', $key)) {
                throw new RuntimeException($this->translator->trans('That doesn\'t look like an API key (letters, digits, - and _ only, no spaces).'));
            }
            $writes[self::KEY] = $this->cipher->encrypt($key, self::PURPOSE);
        }

        foreach (array_keys(self::MODELS) as $name) {
            $model = trim((string) ($body['models'][$name] ?? ''));
            // Goes into the request URL: model names only.
            if ($model !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $model)) {
                throw new RuntimeException($this->translator->trans('"{model}" is not a valid model name (e.g. gemini-flash-latest).', ['model' => mb_substr($model, 0, 64)]));
            }
            $writes[$name] = $model;
        }

        foreach ($writes as $name => $value) {
            $this->settings->set(self::PREFIX . $name, $value);
        }
        $this->values = null;
    }

    /** @return array{key_set: bool, key_hint: string, key_source: ?string, key_unreadable: bool, models: list<array{name: string, value: string, default: string}>} */
    public function overview(Config $env): array
    {
        $saved = $this->values();
        $config = $this->config($env);
        $key = $config->get(self::KEY);
        $models = [];
        foreach (self::MODELS as $name => $default) {
            $models[] = ['name' => $name, 'value' => $config->get($name), 'default' => $default];
        }

        return [
            'key_set'        => $key !== '',
            'key_hint'       => $key !== '' ? '…' . substr($key, -4) : '',
            'key_source'     => $key === '' ? null : (array_key_exists(self::KEY, $saved) ? 'settings' : 'env'),
            'key_unreadable' => $this->unreadable,
            'models'         => $models,
        ];
    }

    /** @return array<string, string> */
    private function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        $this->values = [];
        $this->unreadable = false;
        foreach ($this->settings->allValues() as $name => $value) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            $key = substr($name, strlen(self::PREFIX));
            if ($key !== self::KEY && !isset(self::MODELS[$key])) {
                continue;
            }
            if (SettingsCipher::isEncrypted($value)) {
                $plain = $this->cipher->decrypt($value, self::PURPOSE);
                $this->unreadable = $this->unreadable || $plain === null;
                $value = $plain ?? '';
            }
            $this->values[$key] = $value;
        }

        return $this->values;
    }
}
