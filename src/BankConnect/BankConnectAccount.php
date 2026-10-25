<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * A connected bank account (getAccount(), or a row of listAccounts()).
 *
 * The IBAN and the account number are masked; the full IBAN is never
 * returned. Balances are read with listBalances().
 */
final class BankConnectAccount extends BankConnectResource
{
    public function accountId(): string
    {
        return $this->str('accountId');
    }

    public function connectionId(): string
    {
        return $this->str('connectionId');
    }

    public function bank(): BankConnectBankRef
    {
        return new BankConnectBankRef($this->object('bank'));
    }

    /** e.g. `TR00 **** **** **** **** **00 00`. */
    public function ibanMasked(): ?string
    {
        return $this->strOrNull('ibanMasked');
    }

    public function accountNumberMasked(): ?string
    {
        return $this->strOrNull('accountNumberMasked');
    }

    /** ISO 4217, e.g. `TRY`. */
    public function currency(): string
    {
        return $this->str('currency');
    }

    public function branchCode(): ?string
    {
        return $this->strOrNull('branchCode');
    }

    public function branchName(): ?string
    {
        return $this->strOrNull('branchName');
    }

    /** The bank's product code of the account, e.g. `CARIHSP`; null when the bank does not send it. */
    public function productCode(): ?string
    {
        return $this->strOrNull('productCode');
    }

    /** The account name the bank shows; null when the bank does not send it. */
    public function displayName(): ?string
    {
        return $this->strOrNull('displayName');
    }

    /** The bank's account type text; null when the bank does not send it. */
    public function accountType(): ?string
    {
        return $this->strOrNull('accountType');
    }

    /** One of the BankAccountStatus constants. */
    public function status(): string
    {
        return $this->str('status');
    }

    public function discoveredAt(): ?string
    {
        return $this->strOrNull('discoveredAt');
    }

    public function freshness(): BankConnectFreshness
    {
        return new BankConnectFreshness($this->object('freshness'));
    }
}
