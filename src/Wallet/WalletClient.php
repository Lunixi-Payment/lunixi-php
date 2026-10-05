<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Wallet — `/api/v1/wallet/*`, the merchant server-to-server surface of the
 * closed-loop wallet (`$lunixi->wallet()`).
 *
 *   $wallet = $lunixi->wallet();
 *   $quote = $wallet->transfers->quoteW2W([...]);
 *   $started = $wallet->transfers->initiateW2W([...], 'w2w-order-1042');
 *   $wallet->transfers->completeW2W($started['operationId'], [...], 'w2w-order-1042-complete');
 *
 * Every route is authenticated by the API key's per-request Ed25519
 * signature, so every call is sent signed, reads included; the merchant, its
 * programs and its end users come from the signing key. The key also needs:
 *   - the `WALLET_SERVICE` product (otherwise 403 `PRODUCT_NOT_ENABLED`), and
 *   - the wallet permission behind the route (otherwise 403
 *     `WALLET_INSUFFICIENT_SCOPE`):
 *       `wallet:*:read` for reads;
 *       `wallet:program|enduser|limit|fee:manage` for set-up;
 *       `wallet:operation:intervene` for moving money inside the wallet
 *         (W2W, payments, collect, top-ups);
 *       `wallet:bank-payout:operate` for IBAN transfers and bank withdrawals
 *         (GROWTH plan);
 *       `wallet:qr:manage` for QR codes;
 *       `wallet:bulk:create` to create a bulk payout and `wallet:bulk:approve`
 *         to submit, approve, process and retry it; the approving key must
 *         differ from the key that created and submitted the batch
 *         (four-eyes);
 *       `wallet:enduser:kyc-override` for the KYC level PUT.
 *
 * Amounts are minor units as decimal strings (`"12550"` = 125.50 TRY); a
 * non-negative int is sent as its string, a float is refused. A call that
 * moves money for an end user names that end user (`endUserId`,
 * `buyerEndUserId`); the source must belong to them or the answer is 404
 * `WALLET_NOT_FOUND`. Routes that change state take a required
 * Idempotency-Key (1-128 characters): reuse it when retrying the same
 * operation.
 *
 * Requests are checked against {@see WalletRoutes} before they are sent: an
 * unknown or missing field, a non-digit amount or a missing Idempotency-Key
 * is a ConfigurationException and nothing goes out. Responses are the
 * envelope's `data`, unwrapped; lists return a {@see WalletPage}.
 */
final class WalletClient
{
    public WalletPrograms $programs;

    public WalletEndUsers $endUsers;

    public WalletAccounts $wallets;

    public WalletDeposits $deposits;

    public WalletCorporate $corporate;

    public WalletFees $fees;

    public WalletTransfers $transfers;

    public WalletWithdrawals $withdrawals;

    public WalletOperations $operations;

    public WalletTopups $topups;

    public WalletPayments $payments;

    public WalletQr $qr;

    public WalletBulkPayouts $bulkPayouts;

    private WalletInvoker $invoker;

    public function __construct(ApiClient $api)
    {
        $this->invoker = new WalletInvoker($api);
        $this->programs = new WalletPrograms($this->invoker);
        $this->endUsers = new WalletEndUsers($this->invoker);
        $this->wallets = new WalletAccounts($this->invoker);
        $this->deposits = new WalletDeposits($this->invoker);
        $this->corporate = new WalletCorporate($this->invoker);
        $this->fees = new WalletFees($this->invoker);
        $this->transfers = new WalletTransfers($this->invoker);
        $this->withdrawals = new WalletWithdrawals($this->invoker);
        $this->operations = new WalletOperations($this->invoker);
        $this->topups = new WalletTopups($this->invoker);
        $this->payments = new WalletPayments($this->invoker);
        $this->qr = new WalletQr($this->invoker);
        $this->bulkPayouts = new WalletBulkPayouts($this->invoker);
    }

    /**
     * Checks the key reaches the wallet: signature, the WALLET_SERVICE
     * product and a `wallet:*:manage` permission (a read-only key gets 403).
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function ping(): array
    {
        return Envelope::data($this->invoker->call('ping'));
    }
}
