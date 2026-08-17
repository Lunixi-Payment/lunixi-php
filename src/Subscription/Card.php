<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * A saved payment card (response `data`). The plugin never sees the PAN — only
 * the tokenised reference + display metadata (brand / last4 / expiry).
 */
final class Card
{
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

    public function customerId(): string
    {
        return (string) ($this->data['customerId'] ?? '');
    }

    public function brand(): string
    {
        return (string) ($this->data['brand'] ?? '');
    }

    public function last4(): string
    {
        return (string) ($this->data['last4'] ?? '');
    }

    public function expireMonth(): int
    {
        return (int) ($this->data['expireMonth'] ?? 0);
    }

    public function expireYear(): int
    {
        return (int) ($this->data['expireYear'] ?? 0);
    }

    public function isDefault(): bool
    {
        return (bool) ($this->data['isDefault'] ?? false);
    }

    public function isActive(): bool
    {
        return (bool) ($this->data['isActive'] ?? true);
    }

    public function nickname(): string
    {
        return (string) ($this->data['nickname'] ?? '');
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
