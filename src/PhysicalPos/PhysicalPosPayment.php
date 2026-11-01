<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * A physical POS payment: a root payment (SALE, PREAUTH_OPEN,
 * REFUND_UNMATCHED) or a child reversal (VOID, REFUND, PREAUTH_CLOSE,
 * PREAUTH_CANCEL) of a root.
 *
 * Decide on {@see paymentStatus()} only. `APPROVED` / `PARTIALLY_APPROVED`
 * mean the money was taken ({@see approvedAmountMinor()}); `UNKNOWN` means it
 * MAY have been taken — do not start the payment again, the outcome is being
 * verified. `statusReason` is an open set: ignore values you do not know.
 *
 * {@see items()} and {@see refundItems()} are filled only by getPayment();
 * lists and write answers return them empty. Card data is never present:
 * {@see tender()} carries only the masked number (first 6 + last 4, or last 4),
 * brand, entry mode and bank references.
 */
final class PhysicalPosPayment extends PhysicalPosResource
{
    public function paymentId(): string
    {
        return $this->str('paymentId');
    }

    /** The root's own id on a root; the original payment on a child. */
    public function rootPaymentId(): string
    {
        return $this->str('rootPaymentId');
    }

    /** Null on a root. */
    public function originalPaymentId(): ?string
    {
        return $this->strOrNull('originalPaymentId');
    }

    public function isRoot(): bool
    {
        return $this->originalPaymentId() === null;
    }

    /** `API` or `DEVICE_DIRECT` (a sale made directly on the device). */
    public function origin(): string
    {
        return $this->str('origin');
    }

    /** One of PhysicalPosPaymentOperation. */
    public function operation(): string
    {
        return $this->str('operation');
    }

    /** One of PhysicalPosPaymentStatus. */
    public function paymentStatus(): string
    {
        return $this->str('paymentStatus');
    }

    public function statusReason(): ?string
    {
        return $this->strOrNull('statusReason');
    }

    public function isFinal(): bool
    {
        return in_array($this->paymentStatus(), PhysicalPosPaymentStatus::FINAL_STATUSES, true);
    }

    /** Conflicting evidence arrived after the final status; the status does not change. */
    public function outcomeConflict(): bool
    {
        return $this->bool('outcomeConflict');
    }

    /** A MANUAL_REVIEW payment that can now be resolved in the panel. */
    public function resolvable(): bool
    {
        return $this->bool('resolvable');
    }

    public function deviceId(): string
    {
        return $this->str('deviceId');
    }

    public function logicalTerminalId(): ?string
    {
        return $this->strOrNull('logicalTerminalId');
    }

    /** Null when the device chose the bank row. */
    public function terminalAcquirerId(): ?string
    {
        return $this->strOrNull('terminalAcquirerId');
    }

    /** `APP2APP`, `LOCAL_REST` or `VENDOR_CLOUD`. */
    public function channel(): string
    {
        return $this->str('channel');
    }

    public function vendorReference(): ?string
    {
        return $this->strOrNull('vendorReference');
    }

    /** Requested amount, minor units; null when the answer has none (never a made-up 0). */
    public function amountMinor(): ?string
    {
        return $this->strOrNull('amountMinor');
    }

    /** Approved amount, minor units; less than amountMinor() on a partial approval. */
    public function approvedAmountMinor(): ?string
    {
        return $this->strOrNull('approvedAmountMinor');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    /** Null for a single payment. */
    public function installmentCount(): ?int
    {
        return $this->intOrNull('installmentCount');
    }

    public function instrument(): string
    {
        return $this->str('instrument');
    }

    public function merchantReference(): ?string
    {
        return $this->strOrNull('merchantReference');
    }

    /** Root: approved refunds + void + pre-authorisation cancel. */
    public function reversedMinor(): ?string
    {
        return $this->strOrNull('reversedMinor');
    }

    /** Root: pre-authorisation close amount. */
    public function capturedMinor(): ?string
    {
        return $this->strOrNull('capturedMinor');
    }

    /** Root: amount held by a reversal that has no outcome yet. */
    public function reversalReservedMinor(): ?string
    {
        return $this->strOrNull('reversalReservedMinor');
    }

    public function pendingReversalId(): ?string
    {
        return $this->strOrNull('pendingReversalId');
    }

    /** Root: one of PhysicalPosReversalStatus. */
    public function reversalStatus(): string
    {
        return $this->str('reversalStatus');
    }

    public function expiresAt(): ?string
    {
        return $this->strOrNull('expiresAt');
    }

    public function resultDeadlineAt(): ?string
    {
        return $this->strOrNull('resultDeadlineAt');
    }

    public function completedAt(): ?string
    {
        return $this->strOrNull('completedAt');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }

    public function updatedAt(): ?string
    {
        return $this->strOrNull('updatedAt');
    }

    public function rowVersion(): int
    {
        return $this->int('rowVersion');
    }

    /** Increases with every status change; order webhooks of the same payment by it. */
    public function historySeq(): int
    {
        return $this->int('historySeq');
    }

    /**
     * `{tenderStatus, cardMasked, cardBrand, entryMode, acquirerCode, authCode, rrn}` or null.
     *
     * @return array<string,mixed>|null
     */
    public function tender(): ?array
    {
        return $this->objectOrNull('tender');
    }

    /**
     * Sale items with the computed tax breakdown and refund state (getPayment() only).
     *
     * @return array<int,array<string,mixed>>
     */
    public function items(): array
    {
        return $this->list('items');
    }

    /**
     * Items of an item-based refund (getPayment() only).
     *
     * @return array<int,array<string,mixed>>
     */
    public function refundItems(): array
    {
        return $this->list('refundItems');
    }
}
