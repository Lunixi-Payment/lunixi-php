<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Withdrawal to the end user's own bank account
 * (`$lunixi->wallet()->withdrawals`, scope `wallet:bank-payout:operate`).
 * Same two steps as {@see WalletTransfers}: quote, initiate, complete.
 */
final class WalletWithdrawals
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * @param array<string,mixed> $request endUserId, sourceWalletAccountId, beneficiaryName, iban, amount,
     *   currency; optional bankCode, paymentPurpose.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function quote(array $request): array
    {
        return Envelope::data($this->invoker->call('quoteBankWithdrawal', [], $request));
    }

    /**
     * @param array<string,mixed> $request The quote fields plus optional scheduleVersionId,
     *   otpDestination, otpChannel, locale.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function initiate(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('initiateBankWithdrawal', [], $request, $idempotencyKey));
    }

    /**
     * @param array<string,mixed> $request endUserId, integrityHash, nonce; optional otpCode, trustedActionToken.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function complete(string $operationId, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call(
            'completeBankWithdrawal',
            ['operationId' => $operationId],
            WalletInvoker::withOperationId($operationId, $request),
            $idempotencyKey
        ));
    }

    /**
     * @return array<string,mixed> `{operation, history}`
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function get(string $operationId): array
    {
        return Envelope::data($this->invoker->call('getWithdrawal', ['operationId' => $operationId]));
    }
}
