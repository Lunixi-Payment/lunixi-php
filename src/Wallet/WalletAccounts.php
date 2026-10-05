<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Wallets and their currency accounts (`$lunixi->wallet()->wallets`).
 */
final class WalletAccounts
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * Adds a currency account to a wallet.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function createAccount(string $walletId, string $currency, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('createWalletAccount', ['walletId' => $walletId], ['currency' => $currency], $idempotencyKey));
    }

    /**
     * Resolves a wallet number or phone to `{exists, walletNo, maskedName}`
     * before a transfer.
     *
     * @param array{programId?:string, phone?:string, walletNo?:string} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function lookup(array $request): array
    {
        return Envelope::data($this->invoker->call('lookupWallet', [], $request));
    }
}
