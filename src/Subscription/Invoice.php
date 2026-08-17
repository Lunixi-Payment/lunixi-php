<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * A subscription invoice (response `data`). All amounts are in MINOR units.
 * Status follows InvoiceStatus::* (DRAFT → OPEN → PAID|UNCOLLECTIBLE|VOID).
 */
final class Invoice
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

    public function invoiceNumber(): string
    {
        return (string) ($this->data['invoiceNumber'] ?? '');
    }

    public function subscriptionId(): string
    {
        return (string) ($this->data['subscriptionId'] ?? '');
    }

    /** One of the InvoiceStatus::* constants. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function currency(): string
    {
        return (string) ($this->data['currency'] ?? '');
    }

    /** Total payable in minor units. */
    public function totalAmount(): int
    {
        return (int) ($this->data['totalAmount'] ?? 0);
    }

    public function subtotalAmount(): int
    {
        return (int) ($this->data['subtotalAmount'] ?? 0);
    }

    public function taxAmount(): int
    {
        return (int) ($this->data['taxAmount'] ?? 0);
    }

    public function amountPaid(): int
    {
        return (int) ($this->data['amountPaid'] ?? 0);
    }

    public function attemptCount(): int
    {
        return (int) ($this->data['attemptCount'] ?? 0);
    }

    public function paymentIntentId(): ?string
    {
        $value = $this->data['paymentIntentId'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    public function paymentErrorCode(): string
    {
        return (string) ($this->data['paymentErrorCode'] ?? '');
    }

    public function invoicePdfUrl(): ?string
    {
        $value = $this->data['invoicePdfUrl'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    public function billingPeriodStart(): ?string
    {
        $value = $this->data['billingPeriodStart'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    public function billingPeriodEnd(): ?string
    {
        $value = $this->data['billingPeriodEnd'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    public function isPaid(): bool
    {
        return $this->status() === InvoiceStatus::PAID;
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
