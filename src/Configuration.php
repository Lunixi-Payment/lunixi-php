<?php

declare(strict_types=1);

namespace Lunixi\Sdk;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Immutable SDK configuration. Holds the gateway base URL and the merchant's
 * step-up credentials (key id + Ed25519 private key from the Lunixi dashboard),
 * plus transport tuning. Validated eagerly so misconfiguration fails fast.
 */
final class Configuration
{
    private string $baseUrl;
    private string $keyId;
    private string $privateKey;
    private string $environment;
    private string $authTokenPath;
    private float $timeout;
    private int $maxRetries;
    private string $userAgent;

    /**
     * @param array{
     *   baseUrl:string, keyId:string, privateKey:string,
     *   environment?:string, authTokenPath?:string,
     *   timeout?:float|int, maxRetries?:int, userAgent?:string
     * } $options
     * @throws ConfigurationException
     */
    public function __construct(array $options)
    {
        $this->baseUrl = self::requireString($options, 'baseUrl');
        $this->keyId = self::requireString($options, 'keyId');
        $this->privateKey = self::requireString($options, 'privateKey');

        $this->baseUrl = rtrim($this->baseUrl, '/');
        if (!preg_match('#^https?://#i', $this->baseUrl)) {
            throw new ConfigurationException("Configuration 'baseUrl' must be an absolute http(s) URL.");
        }

        $env = strtoupper((string) ($options['environment'] ?? 'LIVE'));
        if ($env !== 'LIVE' && $env !== 'TEST') {
            throw new ConfigurationException("Configuration 'environment' must be 'LIVE' or 'TEST'.");
        }
        $this->environment = $env;

        $this->authTokenPath = '/' . ltrim((string) ($options['authTokenPath'] ?? '/api/v1/auth/token'), '/');
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->maxRetries = max(0, (int) ($options['maxRetries'] ?? 2));
        $this->userAgent = (string) ($options['userAgent'] ?? 'lunixi-php-sdk');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function privateKey(): string
    {
        return $this->privateKey;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function isTest(): bool
    {
        return $this->environment === 'TEST';
    }

    public function authTokenPath(): string
    {
        return $this->authTokenPath;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function maxRetries(): int
    {
        return $this->maxRetries;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /** Stable cache key for the bearer token (per key id + base URL + environment). */
    public function tokenCacheKey(): string
    {
        return 'lunixi_token_' . hash('sha256', $this->keyId . '|' . $this->baseUrl . '|' . $this->environment);
    }

    /** @param array<string,mixed> $options */
    private static function requireString(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException("Configuration '{$key}' is required and must be a non-empty string.");
        }
        return $value;
    }
}
