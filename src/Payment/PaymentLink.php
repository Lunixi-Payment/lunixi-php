<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A payment link (the response `data` of create/get/update and the actions, or
 * one row of a list).
 *
 * The full link carries every definition field flat at the top level
 * (`title`, `amountMinor`, `items`, `recipient`, …) next to its state,
 * `counters` and `availability`. A list row carries a subset: the accessors of
 * fields a row does not have return their empty value — call
 * PaymentLinkClient::get() for the full link. `get()`/`raw()` expose every
 * field. Amounts are integers in minor units; times are ISO-8601 UTC strings.
 */
final class PaymentLink
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
        return self::str($this->data['id'] ?? '');
    }

    /** 10 characters, e.g. `K7M2Q9XR4T`. */
    public function shortCode(): string
    {
        return self::str($this->data['shortCode'] ?? '');
    }

    /** `https://pay.lunixi.com/<shortCode>` — the address you share. */
    public function url(): string
    {
        return self::str($this->data['url'] ?? '');
    }

    /** `TEST` or `LIVE` — always the environment of the key that created it. */
    public function environment(): string
    {
        return self::str($this->data['environment'] ?? '');
    }

    /** One of the PaymentLinkState constants. */
    public function state(): string
    {
        return self::str($this->data['state'] ?? '');
    }

    /** `NONE`, `PENDING`, `APPROVED` or `REJECTED`. A PENDING link cannot be paid or sent yet. */
    public function reviewState(): string
    {
        return self::str($this->data['reviewState'] ?? '');
    }

    /** Definition version; every update creates a new one. */
    public function version(): int
    {
        return (int) ($this->data['version'] ?? 0);
    }

    /** Pass back as `expectedRowVersion` on update() and the actions. */
    public function rowVersion(): int
    {
        return (int) ($this->data['rowVersion'] ?? 0);
    }

    /** One of the PaymentLinkUsage constants. */
    public function usage(): string
    {
        return self::str($this->data['usage'] ?? '');
    }

    /** One of the PaymentLinkAmountMode constants. */
    public function amountMode(): string
    {
        return self::str($this->data['amountMode'] ?? '');
    }

    public function currency(): string
    {
        return self::str($this->data['currency'] ?? '');
    }

    /** FIXED links only; null otherwise. */
    public function amountMinor(): ?int
    {
        return self::intOrNull($this->data['amountMinor'] ?? null);
    }

    public function title(): string
    {
        return self::str($this->data['title'] ?? '');
    }

    /** Your reference, or null when none was set. */
    public function reference(): ?string
    {
        $reference = $this->data['reference'] ?? null;
        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /** @return array<string,string> */
    public function metadata(): array
    {
        $metadata = $this->data['metadata'] ?? [];
        return is_array($metadata) ? $metadata : [];
    }

    public function expiresAt(): ?string
    {
        return self::timeOrNull($this->data['expiresAt'] ?? null);
    }

    public function createdAt(): ?string
    {
        return self::timeOrNull($this->data['createdAt'] ?? null);
    }

    /** Successful payments so far. */
    public function paidCount(): int
    {
        return (int) ($this->counters()['paidCount'] ?? $this->data['paidCount'] ?? 0);
    }

    /** Sum of captured amounts, minor units. */
    public function collectedAmountMinor(): int
    {
        return (int) ($this->counters()['collectedAmountMinor'] ?? $this->data['collectedAmountMinor'] ?? 0);
    }

    /**
     * paidCount, collectedAmountMinor, refundedAmountMinor, soldQuantity,
     * reservedQuantity, attemptCount (full link only).
     *
     * @return array<string,int>
     */
    public function counters(): array
    {
        $counters = $this->data['counters'] ?? [];
        return is_array($counters) ? $counters : [];
    }

    /**
     * soldOut, remainingQuantity (null = unlimited), inProgress (SINGLE_USE:
     * another buyer is paying right now), items[{itemKey, soldOut,
     * remainingQuantity}] (full link only).
     *
     * @return array<string,mixed>
     */
    public function availability(): array
    {
        $availability = $this->data['availability'] ?? [];
        return is_array($availability) ? $availability : [];
    }

    /** Amount fields cannot be edited: a payment is in progress or the link has been paid. */
    public function isAmountLocked(): bool
    {
        return ($this->data['amountLocked'] ?? false) === true;
    }

    /** True when create() returned the link stored under the same Idempotency-Key. */
    public function wasReplayed(): bool
    {
        return ($this->data['replayed'] ?? false) === true;
    }

    public function isActive(): bool
    {
        return $this->state() === PaymentLinkState::ACTIVE;
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
    private static function str($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param mixed $value */
    private static function intOrNull($value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /** @param mixed $value */
    private static function timeOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
