<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * An outgoing transfer (BankTransferClient::get(), a row of list(), or
 * BankTransferCreated::transfer()).
 *
 * The receiver's IBAN and name are masked; the full values are never
 * returned. Amounts are integers in minor units. Unlike the read routes, a
 * field without a value is sent as null; the accessors return null for it.
 */
final class BankTransfer extends BankConnectResource
{
    public function id(): string
    {
        return $this->str('id');
    }

    public function connectionId(): string
    {
        return $this->str('connectionId');
    }

    public function accountId(): string
    {
        return $this->str('accountId');
    }

    /** `test` or `live`. */
    public function environment(): string
    {
        return $this->str('environment');
    }

    /** One of the BankTransferStatus constants. */
    public function status(): string
    {
        return $this->str('status');
    }

    /** One of the BankTransferRail constants; null until the bank chooses. */
    public function rail(): ?string
    {
        return $this->strOrNull('rail');
    }

    public function amountMinor(): int
    {
        return $this->int('amountMinor');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    public function sourceIbanMasked(): ?string
    {
        return $this->strOrNull('sourceIbanMasked');
    }

    public function receiverIbanMasked(): string
    {
        return $this->str('receiverIbanMasked');
    }

    public function receiverNameMasked(): string
    {
        return $this->str('receiverNameMasked');
    }

    /** EFT code of the receiver's bank. */
    public function receiverBankCode(): ?string
    {
        return $this->strOrNull('receiverBankCode');
    }

    public function paymentPurpose(): ?string
    {
        return $this->strOrNull('paymentPurpose');
    }

    public function description(): ?string
    {
        return $this->strOrNull('description');
    }

    /** The Idempotency-Key the transfer was created with. */
    public function idempotencyKey(): string
    {
        return $this->str('idempotencyKey');
    }

    /** `bank_connect.transfer.*` code when FAILED, RETURNED or CANCELED. */
    public function failureCode(): ?string
    {
        return $this->strOrNull('failureCode');
    }

    /** The bank's answer could not be confirmed; Lunixi is checking it with the bank. */
    public function outcomeUnknown(): bool
    {
        return $this->bool('outcomeUnknown');
    }

    /** One of the BankTransferInitiatedVia constants. */
    public function initiatedVia(): string
    {
        return $this->str('initiatedVia');
    }

    /** `NOT_SCREENED`, `CLEAR`, `POTENTIAL_MATCH` or `ERROR`; informational, it never stops a transfer. */
    public function sanctionStatus(): string
    {
        return $this->str('sanctionStatus');
    }

    /** The outgoing account transaction once it is matched (BankConnectClient::getTransaction()). */
    public function bankTransactionId(): ?string
    {
        return $this->strOrNull('bankTransactionId');
    }

    /** The bank's own status text of the transfer. */
    public function bankState(): ?string
    {
        return $this->strOrNull('bankState');
    }

    public function createdAt(): ?string
    {
        return $this->strOrNull('createdAt');
    }

    public function submittedAt(): ?string
    {
        return $this->strOrNull('submittedAt');
    }

    public function completedAt(): ?string
    {
        return $this->strOrNull('completedAt');
    }

    public function failedAt(): ?string
    {
        return $this->strOrNull('failedAt');
    }

    public function returnedAt(): ?string
    {
        return $this->strOrNull('returnedAt');
    }

    public function canceledAt(): ?string
    {
        return $this->strOrNull('canceledAt');
    }

    /** Dashboard transfers waiting for the e-mail code: when the code expires. */
    public function otpExpiresAt(): ?string
    {
        return $this->strOrNull('otpExpiresAt');
    }

    /** COMPLETED, FAILED, RETURNED or CANCELED (a COMPLETED transfer can still become RETURNED). */
    public function isSettled(): bool
    {
        return in_array($this->status(), [BankTransferStatus::COMPLETED, BankTransferStatus::FAILED, BankTransferStatus::RETURNED, BankTransferStatus::CANCELED], true);
    }
}
