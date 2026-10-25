<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * One exchange rate on a connection (BankFxRates::items()). Rates are decimal
 * strings exactly as the bank sends them; do not round-trip them through float.
 */
final class BankFxRate extends BankConnectResource
{
    public function currency(): string
    {
        return $this->str('currency');
    }

    public function pairCurrency(): string
    {
        return $this->str('pairCurrency');
    }

    public function buyRate(): string
    {
        return $this->str('buyRate');
    }

    public function sellRate(): string
    {
        return $this->str('sellRate');
    }

    public function spread(): ?string
    {
        return $this->strOrNull('spread');
    }

    public function updatedAt(): ?string
    {
        return $this->strOrNull('updatedAt');
    }
}
