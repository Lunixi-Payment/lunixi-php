<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * The latest balance of one account (a row of listBalances()). Amounts are
 * integers in minor units: `125050` is 1,250.50 TRY. `availableMinor` and
 * `blockedMinor` are null when the bank does not report them.
 */
final class BankConnectBalance extends BankConnectResource
{
    public function accountId(): string
    {
        return $this->str('accountId');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    public function balanceMinor(): string
    {
        return $this->money('balanceMinor');
    }

    public function availableMinor(): ?string
    {
        return $this->moneyOrNull('availableMinor');
    }

    public function blockedMinor(): ?string
    {
        return $this->moneyOrNull('blockedMinor');
    }

    /** The time the bank reported this balance for. */
    public function asOf(): ?string
    {
        return $this->strOrNull('asOf');
    }

    /** Available balance without the overdraft line; null when the bank does not report it separately. */
    public function availableWithoutOverdraftMinor(): ?string
    {
        return $this->moneyOrNull('availableWithoutOverdraftMinor');
    }

    /** The unused overdraft line; null when the bank does not report it. */
    public function overdraftAvailableMinor(): ?string
    {
        return $this->moneyOrNull('overdraftAvailableMinor');
    }
}
