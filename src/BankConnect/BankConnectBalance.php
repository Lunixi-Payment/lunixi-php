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

    public function balanceMinor(): int
    {
        return $this->int('balanceMinor');
    }

    public function availableMinor(): ?int
    {
        return $this->intOrNull('availableMinor');
    }

    public function blockedMinor(): ?int
    {
        return $this->intOrNull('blockedMinor');
    }

    /** The time the bank reported this balance for. */
    public function asOf(): ?string
    {
        return $this->strOrNull('asOf');
    }

    /** Available balance without the overdraft line; null when the bank does not report it separately. */
    public function availableWithoutOverdraftMinor(): ?int
    {
        return $this->intOrNull('availableWithoutOverdraftMinor');
    }

    /** The unused overdraft line; null when the bank does not report it. */
    public function overdraftAvailableMinor(): ?int
    {
        return $this->intOrNull('overdraftAvailableMinor');
    }
}
