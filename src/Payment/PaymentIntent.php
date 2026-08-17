<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A payment intent as returned by capture/refund/void/get (the response `data`).
 * Read-only typed accessors over the verified fields; `get()`/`raw()` expose the
 * rest. Amounts are integers in minor units.
 */
final class PaymentIntent
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

    /** One of the PaymentStatus::* constants. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function amount(): int
    {
        return (int) ($this->data['amount'] ?? 0);
    }

    public function currency(): string
    {
        return (string) ($this->data['currency'] ?? '');
    }

    public function orderId(): ?string
    {
        return isset($this->data['orderId']) ? (string) $this->data['orderId'] : null;
    }

    public function merchantId(): ?string
    {
        return isset($this->data['merchantId']) ? (string) $this->data['merchantId'] : null;
    }

    public function installment(): int
    {
        return (int) ($this->data['installment'] ?? 1);
    }

    public function is3D(): bool
    {
        return (bool) ($this->data['is3D'] ?? false);
    }

    public function providerCode(): ?string
    {
        return isset($this->data['providerCode']) ? (string) $this->data['providerCode'] : null;
    }

    /** 3DS redirect URL when the intent is awaiting 3-D Secure (else null). */
    public function threeDRedirectUrl(): ?string
    {
        $url = $this->data['threeDRedirectUrl'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Epoch milliseconds, or null. */
    public function createdAt(): ?int
    {
        return isset($this->data['createdAt']) ? (int) $this->data['createdAt'] : null;
    }

    public function updatedAt(): ?int
    {
        return isset($this->data['updatedAt']) ? (int) $this->data['updatedAt'] : null;
    }

    /** Decoded merchant metadata (from `metadataJson`), or empty array. @return array<string,mixed> */
    public function metadata(): array
    {
        $json = $this->data['metadataJson'] ?? null;
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    public function isCaptured(): bool
    {
        return in_array($this->status(), PaymentStatus::CAPTURED_STATES, true);
    }

    public function isAuthorized(): bool
    {
        return $this->status() === PaymentStatus::AUTHORIZED;
    }

    public function isFailed(): bool
    {
        return $this->status() === PaymentStatus::FAILED;
    }

    public function isVoided(): bool
    {
        return $this->status() === PaymentStatus::VOIDED;
    }

    public function awaiting3D(): bool
    {
        return $this->status() === PaymentStatus::AWAITING_3D;
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
}
