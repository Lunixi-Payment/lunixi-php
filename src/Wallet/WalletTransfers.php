<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Transfers out of an end user's wallet (`$lunixi->wallet()->transfers`):
 * wallet-to-wallet (W2W, scope `wallet:operation:intervene`) and to an IBAN
 * (W2IBAN, scope `wallet:bank-payout:operate`).
 *
 * Money moves in two steps: `initiate*` reserves the limit and returns
 * `{operationId, integrityHash, nonce, otpRequired, …}`; `complete*` with the
 * same `integrityHash` and `nonce` (and `otpCode` when `otpRequired`) commits
 * it. Quote first to show the fee; pass its `scheduleVersionId` to
 * `initiate*` to hold that fee. Every request names the end user
 * (`endUserId`) and the source account must be theirs, otherwise the answer
 * is 404 `WALLET_NOT_FOUND`. Amounts are minor-unit digit strings.
 */
final class WalletTransfers
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * @param array<string,mixed> $request endUserId, sourceWalletAccountId, amount, currency; optional
     *   destinationWalletNo, destinationPhone, paymentPurpose.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function quoteW2W(array $request): array
    {
        return Envelope::data($this->invoker->call('quoteW2W', [], $request));
    }

    /**
     * @param array<string,mixed> $request The quote fields plus optional scheduleVersionId,
     *   otpDestination, otpChannel (WalletOtpChannel), locale.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function initiateW2W(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('initiateW2W', [], $request, $idempotencyKey));
    }

    /**
     * Commits an initiated W2W transfer. `operationId` is filled into the
     * body from the first argument.
     *
     * @param array<string,mixed> $request endUserId, integrityHash, nonce; optional otpCode, trustedActionToken.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function completeW2W(string $operationId, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call(
            'completeW2W',
            ['operationId' => $operationId],
            WalletInvoker::withOperationId($operationId, $request),
            $idempotencyKey
        ));
    }

    /**
     * @param array<string,mixed> $request endUserId, sourceWalletAccountId, beneficiaryName, iban, amount,
     *   currency; optional bankCode, paymentPurpose.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function quoteW2Iban(array $request): array
    {
        return Envelope::data($this->invoker->call('quoteW2Iban', [], $request));
    }

    /**
     * @param array<string,mixed> $request The quote fields plus optional scheduleVersionId,
     *   otpDestination, otpChannel, locale.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function initiateW2Iban(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('initiateW2Iban', [], $request, $idempotencyKey));
    }

    /**
     * @param array<string,mixed> $request endUserId, integrityHash, nonce; optional otpCode, trustedActionToken.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function completeW2Iban(string $operationId, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call(
            'completeW2Iban',
            ['operationId' => $operationId],
            WalletInvoker::withOperationId($operationId, $request),
            $idempotencyKey
        ));
    }
}
