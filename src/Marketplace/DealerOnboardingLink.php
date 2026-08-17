<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Marketplace;

/**
 * A sub-dealer self-onboarding link (response of POST
 * /marketplace/dealers/{id}/onboarding-token). `url` is the hosted onboarding
 * wizard (altbayi.lunixi.com); `token` (mp_…) also bootstraps the embedded
 * marketplace SDK.
 */
final class DealerOnboardingLink
{
    public const DEFAULT_HOSTED_BASE = 'https://altbayi.lunixi.com';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $response */
    public function __construct(array $response)
    {
        $data = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : [];
        $this->data = array_merge($response, $data);
    }

    public function token(): string
    {
        return (string) ($this->data['token'] ?? '');
    }

    /** The gateway-issued hosted onboarding URL. */
    public function url(): string
    {
        $url = (string) ($this->data['url'] ?? '');
        return $url !== '' ? $url : rtrim(self::DEFAULT_HOSTED_BASE, '/') . '/m/' . rawurlencode($this->token());
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
