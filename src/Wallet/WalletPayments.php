<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * The end user pays the merchant (`$lunixi->wallet()->payments`, scope
 * `wallet:operation:intervene`).
 *
 * The creditor is the wallet terminal bound to the signing key
 * (403 `WALLET_TERMINAL_*` without one); a `businessAccountId` in the body is
 * refused. approve() names the paying end user as `buyerEndUserId`; a buyer
 * who does not own the operation gets 404 `WALLET_NOT_FOUND`.
 */
final class WalletPayments
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * Asks the buyer (by wallet number or phone) to pay; approve with approve().
     *
     * @param array<string,mixed> $request amount, currency, paymentPurpose; optional buyerWalletNo,
     *   buyerPhone, orderRef, cashierRef, otpDestination, otpChannel, locale.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function request(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('requestPayment', [], $request, $idempotencyKey));
    }

    /**
     * @param array<string,mixed> $request buyerEndUserId, integrityHash, nonce; optional otpCode, trustedActionToken.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function approve(string $operationId, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('approvePayment', ['operationId' => $operationId], $request, $idempotencyKey));
    }

    /**
     * Collects with the payment code the buyer shows (customer-presented).
     *
     * @param array<string,mixed> $request code, amount, currency; optional orderRef, paymentPurpose, locale.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function collect(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('collectByCode', [], $request, $idempotencyKey));
    }

    /**
     * @return array<string,mixed> `{operation, history}`
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function get(string $operationId): array
    {
        return Envelope::data($this->invoker->call('getPayment', ['operationId' => $operationId]));
    }

    /**
     * The commission terms of a business account.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function getCommission(string $businessAccountId): array
    {
        return Envelope::data($this->invoker->call('getBusinessCommission', ['businessAccountId' => $businessAccountId]));
    }
}
