<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * A payment purpose code the bank accepts (BankTransferClient::listPurposes()).
 */
final class BankTransferPurpose extends BankConnectResource
{
    /** Pass it as `paymentPurpose`. */
    public function code(): string
    {
        return $this->str('code');
    }

    public function name(): string
    {
        return $this->str('name');
    }

    /** Display order the bank suggests. */
    public function order(): int
    {
        return $this->int('order');
    }
}
