<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * An exchange quote (BankFxClient::createQuote()). Use it with createOrder()
 * before expiresAt(); the bank sets the validity (see validForSeconds()).
 * Nothing is booked by a quote; one quote serves one order.
 */
final class BankFxQuote extends BankConnectResource
{
    /** Pass it as `quoteId`. */
    public function id(): string
    {
        return $this->str('id');
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

    /** One of the BankFxRequestedSide constants. */
    public function requestedSide(): string
    {
        return $this->str('requestedSide');
    }

    /** Decimal string. */
    public function appliedRate(): string
    {
        return $this->str('appliedRate');
    }

    public function taxAmountMinor(): ?int
    {
        return $this->intOrNull('taxAmountMinor');
    }

    public function expiresAt(): ?string
    {
        return $this->strOrNull('expiresAt');
    }

    /** Seconds left when the quote was answered. */
    public function validForSeconds(): int
    {
        return $this->int('validForSeconds');
    }

    /** The bank warns that the source balance may not cover it. */
    public function insufficientBalance(): bool
    {
        return $this->bool('insufficientBalance');
    }

    /** The amount is below the bank minimum. */
    public function belowMinimum(): bool
    {
        return $this->bool('belowMinimum');
    }

    public function createdAt(): ?string
    {
        return $this->strOrNull('createdAt');
    }
}
