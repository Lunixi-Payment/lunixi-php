<?php

declare(strict_types=1);

namespace app\components;

final class Settings
{
    public function __construct(
        public readonly string $merchantId,
        public readonly string $environment,
        public readonly string $keyId,
        public readonly string $privateKey,
        public readonly string $webhookSecret,
        public readonly string $encryptionKey,
        public readonly string $baseUrl,
        public readonly string $mode = 'hosted',
        public readonly bool $preauth = false,
    ) {
        if ($merchantId === '' || !in_array($environment, ['TEST', 'LIVE'], true) || !in_array($mode, ['hosted', 'embedded'], true)) {
            throw new \InvalidArgumentException('Configure a merchant, key environment and checkout mode.');
        }
        $url = parse_url($baseUrl);
        if (($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('Configure a trusted HTTPS callback base URL.');
        }
        if (strlen($encryptionKey) !== 32) {
            throw new \InvalidArgumentException('An independent 32-byte encryption key is required.');
        }
    }
    public static function fromEnvironment(): self
    {
        $key = base64_decode((string) getenv('LUNIXI_ENCRYPTION_KEY'), true);
        return new self((string) getenv('LUNIXI_MERCHANT_ID'), (string) getenv('LUNIXI_ENVIRONMENT'), (string) getenv('LUNIXI_KEY_ID'), (string) getenv('LUNIXI_PRIVATE_KEY'), (string) getenv('LUNIXI_WEBHOOK_SECRET'), $key === false ? '' : $key, rtrim((string) getenv('LUNIXI_APP_URL'), '/'), getenv('LUNIXI_CHECKOUT_MODE') ?: 'hosted', getenv('LUNIXI_PREAUTH') === 'true');
    }
}
