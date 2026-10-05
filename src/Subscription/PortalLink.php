<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * A customer-portal link (response `data` of creating a portal link). Its
 * `token` (cp_…) is presented to the hosted Lunixi portal, which validates it,
 * verifies the customer (OTP) and runs the subscription screen — the
 * redirect-based alternative to the embedded SDK session.
 */
final class PortalLink
{
    public const DEFAULT_HOSTED_BASE = 'https://portal.lunixi.com';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? '');
    }

    public function token(): string
    {
        return (string) ($this->data['token'] ?? '');
    }

    public function customerId(): string
    {
        return (string) ($this->data['customerId'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function expiresAt(): ?string
    {
        $value = $this->data['expiresAt'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /** The hosted portal URL a customer is redirected to. */
    public function hostedUrl(string $base = self::DEFAULT_HOSTED_BASE): string
    {
        return rtrim($base, '/') . '/?token=' . rawurlencode($this->token());
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
