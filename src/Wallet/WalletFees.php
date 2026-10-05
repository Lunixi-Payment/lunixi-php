<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Fee quotes (`$lunixi->wallet()->fees`).
 */
final class WalletFees
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * The fee, tax and total charge an operation would cost.
     *
     * @param array<string,mixed> $request programId, operationType, currency, amount (minor units); optional at.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function quote(array $request): array
    {
        return Envelope::data($this->invoker->call('quoteFees', [], $request));
    }
}
