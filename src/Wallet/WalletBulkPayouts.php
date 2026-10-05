<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Bulk payouts (`$lunixi->wallet()->bulkPayouts`), four-eyes. create() needs
 * `wallet:bulk:create`; submit(), approve(), reject(), process() and
 * retryFailed() need `wallet:bulk:approve`. The maker creates and submits the
 * batch; the approving key must be a DIFFERENT key than the one that created
 * and submitted it (the principal is the signing key id; wallet-service
 * enforces the difference).
 */
final class WalletBulkPayouts
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * @param array<string,mixed> $request programId, sourceWalletAccountId, currency, items; optional reason.
     *   Each item: targetType (WalletBulkTargetType), targetRef, amount (minor units); optional beneficiaryName.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function create(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('createBulkPayout', [], $request, $idempotencyKey));
    }

    /**
     * @param array{programId?:string, status?:string, limit?:int} $filters
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function list(array $filters = []): WalletPage
    {
        return new WalletPage($this->invoker->call('listBulkPayouts', [], null, null, $filters));
    }

    /**
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function get(string $batchId): array
    {
        return Envelope::data($this->invoker->call('getBulkPayout', ['batchId' => $batchId]));
    }

    /**
     * @param array{status?:string, limit?:int} $filters
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function listItems(string $batchId, array $filters = []): WalletPage
    {
        return new WalletPage($this->invoker->call('listBulkPayoutItems', ['batchId' => $batchId], null, null, $filters));
    }

    /**
     * Sends the batch for approval.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function submit(string $batchId): array
    {
        return Envelope::data($this->invoker->call('submitBulkPayout', ['batchId' => $batchId]));
    }

    /**
     * Approves the batch; `approve => false` declines it with `reason`.
     *
     * @param array{approve?:bool, reason?:string} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function approve(string $batchId, array $request = []): array
    {
        $body = array_merge(['approve' => true], $request);
        if (!is_bool($body['approve'])) {
            throw new ConfigurationException('approveBulkPayout.approve must be a boolean.');
        }

        return Envelope::data($this->invoker->call('approveBulkPayout', ['batchId' => $batchId], $body));
    }

    /**
     * @param array{reason?:string} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function reject(string $batchId, array $request = []): array
    {
        return Envelope::data($this->invoker->call('rejectBulkPayout', ['batchId' => $batchId], $request));
    }

    /**
     * Pays out an approved batch.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function process(string $batchId): array
    {
        return Envelope::data($this->invoker->call('processBulkPayout', ['batchId' => $batchId]));
    }

    /**
     * Retries the failed items of a processed batch.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function retryFailed(string $batchId): array
    {
        return Envelope::data($this->invoker->call('retryFailedBulkPayout', ['batchId' => $batchId]));
    }
}
