<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * A subscription as returned by the subscription REST surface (response `data`).
 * Read-only typed accessors; `get()`/`raw()` expose anything not modelled.
 * Money is in MINOR units (integer); timestamps are ISO-8601 strings.
 */
final class Subscription
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data The response `data` object. */
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

    public function merchantId(): string
    {
        return (string) ($this->data['merchantId'] ?? '');
    }

    public function customerId(): string
    {
        return (string) ($this->data['customerId'] ?? '');
    }

    public function planId(): string
    {
        return (string) ($this->data['planId'] ?? '');
    }

    public function planVersion(): int
    {
        return (int) ($this->data['planVersion'] ?? 0);
    }

    /** One of the SubscriptionStatus::* constants. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    /** Price snapshot in minor units. */
    public function priceAmount(): int
    {
        return (int) ($this->data['priceSnapshotAmount'] ?? 0);
    }

    public function currency(): string
    {
        return (string) ($this->data['priceSnapshotCurrency'] ?? '');
    }

    public function collectionMethod(): string
    {
        return (string) ($this->data['collectionMethod'] ?? '');
    }

    public function billingTimezone(): string
    {
        return (string) ($this->data['billingTimezone'] ?? '');
    }

    public function currentPeriodStart(): ?string
    {
        return self::str($this->data['currentPeriodStart'] ?? null);
    }

    public function currentPeriodEnd(): ?string
    {
        return self::str($this->data['currentPeriodEnd'] ?? null);
    }

    public function nextBillingAt(): ?string
    {
        return self::str($this->data['nextBillingAt'] ?? null);
    }

    public function trialStart(): ?string
    {
        return self::str($this->data['trialStart'] ?? null);
    }

    public function trialEnd(): ?string
    {
        return self::str($this->data['trialEnd'] ?? null);
    }

    public function pausedAt(): ?string
    {
        return self::str($this->data['pausedAt'] ?? null);
    }

    public function canceledAt(): ?string
    {
        return self::str($this->data['canceledAt'] ?? null);
    }

    public function cancelEffectiveAt(): ?string
    {
        return self::str($this->data['cancelEffectiveAt'] ?? null);
    }

    public function cancelAtPeriodEnd(): bool
    {
        return (bool) ($this->data['cancelAtPeriodEnd'] ?? false);
    }

    public function dunningStep(): int
    {
        return (int) ($this->data['dunningStep'] ?? 0);
    }

    public function pendingChangeType(): ?string
    {
        return self::str($this->data['pendingChangeType'] ?? null);
    }

    public function createdAt(): ?string
    {
        return self::str($this->data['createdAt'] ?? null);
    }

    public function updatedAt(): ?string
    {
        return self::str($this->data['updatedAt'] ?? null);
    }

    public function isActive(): bool
    {
        return in_array($this->status(), SubscriptionStatus::ACTIVE_STATES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status(), SubscriptionStatus::TERMINAL_STATES, true);
    }

    public function isTrialing(): bool
    {
        return $this->status() === SubscriptionStatus::TRIALING;
    }

    public function isPaused(): bool
    {
        return $this->status() === SubscriptionStatus::PAUSED;
    }

    /** @return mixed Any raw field by name. */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    /** @param mixed $value */
    private static function str($value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }
}
