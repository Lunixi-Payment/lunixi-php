<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Top-up by bank transfer (`$lunixi->wallet()->deposits`): the bank details
 * an end user pays into.
 */
final class WalletDeposits
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * Bank transfer details the end user pays into to top up.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function listInstructions(string $endUserId): array
    {
        return Envelope::data($this->invoker->call('listDepositInstructions', ['endUserId' => $endUserId]));
    }

    /**
     * @param array{walletAccountId:string, currency?:string} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function createInstruction(string $endUserId, array $request): array
    {
        return Envelope::data($this->invoker->call('createDepositInstruction', ['endUserId' => $endUserId], $request));
    }

    /**
     * Test only: books an incoming bank transfer as the bank rail would.
     *
     * @param array<string,mixed> $request programId, railId, amount (minor units), currency, rawReference;
     *   optional senderName, senderIban, creditedIban, receiptNumber.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function simulateInboundCredit(array $request): array
    {
        return Envelope::data($this->invoker->call('simulateInboundCredit', [], $request));
    }
}
