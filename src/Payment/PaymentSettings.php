<?php

declare(strict_types=1);

namespace App\Payment;

use App\Http\Router;
use App\I18n\Translator;
use App\Repository\SettingRepository;
use App\Service\OrderLinks;
use App\Service\SettingsCipher;
use App\Support\Config;
use RuntimeException;

/**
 * Payment method switches and provider keys edited in Admin → Settings → Payments,
 * so a store owner never has to touch .env.
 *
 * Values are kept in the settings table as "payment.<ENV_KEY>" (the same names as
 * in .env.example). A value saved here wins over .env; a key never saved here falls
 * back to .env, so existing installs keep working. Secrets (API keys, webhook
 * signing secrets) are encrypted with libsodium using a key derived from the
 * store secret (APP_SECRET or var/app-secret): a database dump alone does not
 * reveal them. If that secret changes, saved secrets can't be read any more and
 * the page asks for them again.
 *
 * Payment classes receive config() — the .env Config with these values laid over
 * it — through the container (see config/container.php).
 */
final class PaymentSettings
{
    private const PREFIX = 'payment.';
    private const PURPOSE = 'payment-settings';
    private const MAX_LENGTH = 500;
    private const MAX_NOTE = 1000;

    /**
     * Each method's form. type: secret (encrypted, never shown back), text, bool, textarea.
     * "default" is what a bool means when neither Settings nor .env set it (as the methods read it).
     *
     * @var array<string, array{enabled_default: bool, webhook: ?string, fields: array<string, array{label: string, type: string, required?: bool, placeholder?: string, prefix?: list<string>, default?: bool}>}>
     */
    public const METHODS = [
        'stripe' => [
            'enabled_default' => false,
            'webhook' => 'payment.stripe',
            'fields' => [
                'STRIPE_SECRET_KEY'     => ['label' => 'Secret key', 'type' => 'secret', 'required' => true, 'placeholder' => 'sk_live_… / sk_test_…', 'prefix' => ['sk_', 'rk_']],
                'STRIPE_WEBHOOK_SECRET' => ['label' => 'Webhook signing secret', 'type' => 'secret', 'required' => true, 'placeholder' => 'whsec_…', 'prefix' => ['whsec_']],
            ],
        ],
        'paypal' => [
            'enabled_default' => false,
            'webhook' => null,
            'fields' => [
                'PAYPAL_CLIENT_ID'     => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
                'PAYPAL_CLIENT_SECRET' => ['label' => 'Client secret', 'type' => 'secret', 'required' => true],
                'PAYPAL_SANDBOX'       => ['label' => 'Test mode (sandbox)', 'type' => 'bool', 'default' => true],
            ],
        ],
        'revolut' => [
            'enabled_default' => false,
            'webhook' => 'payment.revolut',
            'fields' => [
                'REVOLUT_SECRET_KEY'     => ['label' => 'Secret API key', 'type' => 'secret', 'required' => true],
                'REVOLUT_WEBHOOK_SECRET' => ['label' => 'Webhook signing secret', 'type' => 'secret', 'required' => true],
                'REVOLUT_SANDBOX'        => ['label' => 'Test mode (sandbox)', 'type' => 'bool', 'default' => true],
            ],
        ],
        'oxapay' => [
            'enabled_default' => true,
            'webhook' => null,
            'fields' => [
                'OXAPAY_MERCHANT_KEY' => ['label' => 'Merchant API key', 'type' => 'secret', 'required' => true],
                'OXAPAY_SANDBOX'      => ['label' => 'Test mode (sandbox)', 'type' => 'bool', 'default' => false],
            ],
        ],
        'bank_transfer' => [
            'enabled_default' => false,
            'webhook' => null,
            'fields' => [
                'BANK_ACCOUNT_NAME' => ['label' => 'Account holder', 'type' => 'text', 'required' => true],
                'BANK_IBAN'         => ['label' => 'IBAN', 'type' => 'text', 'required' => true],
                'BANK_BIC'          => ['label' => 'BIC / SWIFT', 'type' => 'text'],
                'BANK_NAME'         => ['label' => 'Bank', 'type' => 'text'],
            ],
        ],
        'cod' => [
            'enabled_default' => false,
            'webhook' => null,
            'fields' => [
                'COD_NOTE' => ['label' => 'Note for the customer', 'type' => 'textarea', 'placeholder' => 'Please have the exact amount ready when the courier arrives.'],
            ],
        ],
    ];

    /** @var array<string, string>|null decrypted values saved in Settings */
    private ?array $values = null;

    /** @var list<string> keys whose saved secret could not be decrypted */
    private array $unreadable = [];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly OrderLinks $links,
        private readonly Router $router,
        private readonly Translator $translator,
        private readonly SettingsCipher $cipher,
    ) {
    }

    public static function enabledKey(string $method): string
    {
        return 'PAYMENT_' . strtoupper($method) . '_ENABLED';
    }

    /** The .env configuration with the values saved in Settings laid over it. */
    public function config(Config $env): Config
    {
        return $env->withOverrides($this->values());
    }

    /**
     * Saves one method's form: the on/off switch, text fields and switches as posted,
     * secrets only when a new value was typed (empty = keep) or "remove" was ticked.
     *
     * @param array<string, mixed> $body
     */
    public function save(string $method, array $body): void
    {
        $definition = self::METHODS[$method] ?? throw new RuntimeException($this->translator->trans('Unknown payment method.'));
        $fields = is_array($body['field'] ?? null) ? $body['field'] : [];
        $secrets = is_array($body['secret'] ?? null) ? $body['secret'] : [];
        $clear = is_array($body['clear'] ?? null) ? $body['clear'] : [];

        // Validate everything first, so a rejected value saves nothing.
        $writes = [self::enabledKey($method) => !empty($body['enabled']) ? 'true' : 'false'];
        foreach ($definition['fields'] as $key => $field) {
            switch ($field['type']) {
                case 'bool':
                    $writes[$key] = !empty($fields[$key]) ? 'true' : 'false';
                    break;

                case 'text':
                case 'textarea':
                    $value = trim(str_replace("\r\n", "\n", (string) ($fields[$key] ?? '')));
                    $max = $field['type'] === 'textarea' ? self::MAX_NOTE : self::MAX_LENGTH;
                    if (mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                        throw new RuntimeException($this->translator->trans('"{field}" is too long or contains invalid characters.', ['field' => $this->translator->trans($field['label'])]));
                    }
                    if ($field['type'] === 'text') {
                        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
                    }
                    if ($key === 'BANK_IBAN' && $value !== '') {
                        $value = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);
                        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{8,30}$/', $value)) {
                            throw new RuntimeException($this->translator->trans('That IBAN doesn\'t look right (e.g. PT50 0000 0000 0000 0000 0000 0).'));
                        }
                        $value = trim(chunk_split($value, 4, ' '));
                    }
                    $writes[$key] = $value;
                    break;

                case 'secret':
                    if (!empty($clear[$key])) {
                        $writes[$key] = '';
                        break;
                    }
                    $value = trim((string) ($secrets[$key] ?? ''));
                    if ($value === '') {
                        break; // keep the saved one (or the .env one)
                    }
                    if (strlen($value) > self::MAX_LENGTH || preg_match('/\s/', $value)) {
                        throw new RuntimeException($this->translator->trans('"{field}" must be a single value without spaces.', ['field' => $this->translator->trans($field['label'])]));
                    }
                    if (isset($field['prefix']) && !$this->startsWithAny($value, $field['prefix'])) {
                        throw new RuntimeException($this->translator->trans('"{field}" should start with {prefix}.', ['field' => $this->translator->trans($field['label']), 'prefix' => implode(' / ', $field['prefix'])]));
                    }
                    $writes[$key] = $this->cipher->encrypt($value, self::PURPOSE);
                    break;
            }
        }

        foreach ($writes as $key => $value) {
            $this->settings->set(self::PREFIX . $key, $value);
        }
        $this->values = null;
    }

    /**
     * What Admin → Settings shows for each method. Secrets are never sent back:
     * only whether one is set, where it comes from and its last 4 characters.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Config $env, PaymentRegistry $registry): array
    {
        $saved = $this->values();
        $config = $this->config($env);
        $base = $this->links->baseUrl();
        $rows = [];

        foreach (self::METHODS as $id => $definition) {
            $method = $registry->get($id);
            if ($method === null) {
                continue;
            }
            $enabledKey = self::enabledKey($id);
            $fields = [];
            foreach ($definition['fields'] as $key => $field) {
                $source = array_key_exists($key, $saved) ? 'settings' : ($env->has($key) ? 'env' : null);
                $value = $config->get($key);
                $fields[] = [
                    'key'         => $key,
                    'label'       => $field['label'],
                    'type'        => $field['type'],
                    'required'    => $field['required'] ?? false,
                    'placeholder' => $field['placeholder'] ?? '',
                    'source'      => $value === '' ? null : $source,
                    'value'       => $field['type'] === 'secret' ? '' : $value,
                    'checked'     => $field['type'] === 'bool' ? $config->bool($key, $field['default'] ?? false) : false,
                    'is_set'      => $value !== '',
                    'hint'        => $field['type'] === 'secret' && $value !== '' ? '…' . substr($value, -4) : '',
                    'unreadable'  => in_array($key, $this->unreadable, true),
                ];
            }

            $missing = [];
            foreach ($definition['fields'] as $key => $field) {
                if (($field['required'] ?? false) && !$config->has($key)) {
                    $missing[] = $field['label'];
                }
            }

            $rows[] = [
                'id'        => $id,
                'label'     => $method->label(),
                'enabled'   => $config->bool($enabledKey, $definition['enabled_default']),
                'from_env'  => !array_key_exists($enabledKey, $saved) && $env->has($enabledKey),
                'active'    => $method->isEnabled(),
                'missing'   => $missing,
                'webhook'   => $definition['webhook'] !== null ? $base . $this->router->url($definition['webhook']) : null,
                'fields'    => $fields,
            ];
        }

        return $rows;
    }

    /** @return array<string, string> ENV_KEY => value, for every payment value saved in Settings */
    private function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        $this->values = [];
        $this->unreadable = [];
        foreach ($this->settings->allValues() as $name => $value) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            $key = substr($name, strlen(self::PREFIX));
            if (SettingsCipher::isEncrypted($value)) {
                $plain = $this->cipher->decrypt($value, self::PURPOSE);
                if ($plain === null) {
                    $this->unreadable[] = $key;
                    $plain = '';
                }
                $value = $plain;
            }
            $this->values[$key] = $value;
        }

        return $this->values;
    }

    /** @param list<string> $prefixes */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
