<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * A subscription plan (response `data`). Price is in MINOR units. The recurrence
 * rule is exposed raw via `billing()`; common fields have typed accessors.
 */
final class Plan
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

    public function referenceCode(): string
    {
        return (string) ($this->data['referenceCode'] ?? '');
    }

    public function productId(): string
    {
        return (string) ($this->data['productId'] ?? '');
    }

    public function code(): string
    {
        return (string) ($this->data['code'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? '');
    }

    public function description(): string
    {
        return (string) ($this->data['description'] ?? '');
    }

    /** Plan price in minor units. */
    public function price(): int
    {
        return (int) ($this->data['price'] ?? 0);
    }

    public function currency(): string
    {
        return (string) ($this->data['currency'] ?? '');
    }

    public function trialPeriodDays(): int
    {
        return (int) ($this->data['trialPeriodDays'] ?? 0);
    }

    /** @return array<string,mixed> The recurrence rule (frequency, interval, …). */
    public function billing(): array
    {
        return isset($this->data['billing']) && is_array($this->data['billing']) ? $this->data['billing'] : [];
    }

    public function isActive(): bool
    {
        return (bool) ($this->data['isActive'] ?? false);
    }

    public function version(): int
    {
        return (int) ($this->data['version'] ?? 0);
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
