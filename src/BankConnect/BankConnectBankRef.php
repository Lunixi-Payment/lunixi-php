<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/** The bank a connection, an account or a transaction belongs to: `{bankId, eftCode, name?}`. */
final class BankConnectBankRef extends BankConnectResource
{
    public function bankId(): string
    {
        return $this->str('bankId');
    }

    /** The bank's 4-digit EFT code, e.g. `0062`. */
    public function eftCode(): string
    {
        return $this->str('eftCode');
    }

    public function name(): ?string
    {
        return $this->strOrNull('name');
    }
}
