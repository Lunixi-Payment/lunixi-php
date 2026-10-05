<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Top-ups by card and through an agent (`$lunixi->wallet()->topups`, scope
 * `wallet:operation:intervene`).
 */
final class WalletTopups
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * Starts a card top-up. Render `checkoutFormContent` (or follow
     * `redirectUrl` / `threeDsHtml`); the wallet is credited when the payment
     * completes (`wallet.topup.completed`).
     *
     * @param array<string,mixed> $request creditWalletAccountId, amount, currency; optional fundingMerchantId,
     *   savedCardUserKey, savedCardToken, savedConsumerToken, savedPublicCardStorageToken, returnUrl.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function startCard(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('startCardTopup', [], $request, $idempotencyKey));
    }

    /**
     * @return array<string,mixed> `{operation, history}`
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function get(string $operationId): array
    {
        return Envelope::data($this->invoker->call('getTopup', ['operationId' => $operationId]));
    }

    /**
     * Refunds a card top-up to the card. Four eyes: `approverPrincipal` is
     * required and must differ from `requesterPrincipal`; binding them to two
     * real people is your approval flow's job.
     *
     * @param array{reason:string, requesterPrincipal:string, approverPrincipal:string} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function refund(string $operationId, array $request): array
    {
        return Envelope::data($this->invoker->call('refundTopup', ['operationId' => $operationId], $request));
    }

    /**
     * Cash top-up through an agent of the program.
     *
     * @param array{agentId:string, creditWalletAccountId:string, amount:string|int, currency:string} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function agent(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('agentTopup', [], $request, $idempotencyKey));
    }
}
