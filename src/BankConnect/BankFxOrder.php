<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * A currency exchange order (BankFxClient::getOrder(), a row of listOrders(),
 * or BankFxOrderCreated::order()). Amounts are integers in minor units of
 * each account's currency; a field without a value is null.
 */
final class BankFxOrder extends BankConnectResource
{
    public function id(): string
    {
        return $this->str('id');
    }

    public function quoteId(): string
    {
        return $this->str('quoteId');
    }

    public function connectionId(): string
    {
        return $this->str('connectionId');
    }

    public function sourceAccountId(): string
    {
        return $this->str('sourceAccountId');
    }

    public function targetAccountId(): string
    {
        return $this->str('targetAccountId');
    }

    public function sourceCurrency(): string
    {
        return $this->str('sourceCurrency');
    }

    public function targetCurrency(): string
    {
        return $this->str('targetCurrency');
    }

    public function sourceAmountMinor(): int
    {
        return $this->int('sourceAmountMinor');
    }

    public function targetAmountMinor(): int
    {
        return $this->int('targetAmountMinor');
    }

    /** Decimal string. */
    public function appliedRate(): string
    {
        return $this->str('appliedRate');
    }

    public function description(): ?string
    {
        return $this->strOrNull('description');
    }

    public function idempotencyKey(): string
    {
        return $this->str('idempotencyKey');
    }

    /** One of the BankFxOrderStatus constants. */
    public function status(): string
    {
        return $this->str('status');
    }

    /** `bank_connect.fx.*` code when FAILED. */
    public function failureCode(): ?string
    {
        return $this->strOrNull('failureCode');
    }

    public function outcomeUnknown(): bool
    {
        return $this->bool('outcomeUnknown');
    }

    /** One of the BankTransferInitiatedVia constants. */
    public function initiatedVia(): string
    {
        return $this->str('initiatedVia');
    }

    public function sourceBalanceAfterMinor(): ?int
    {
        return $this->intOrNull('sourceBalanceAfterMinor');
    }

    public function targetBalanceAfterMinor(): ?int
    {
        return $this->intOrNull('targetBalanceAfterMinor');
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

    /** Dashboard orders only. */
    public function fxSessionId(): ?string
    {
        return $this->strOrNull('fxSessionId');
    }
}
