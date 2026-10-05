<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Generator;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Money movements of the merchant's end users (`$lunixi->wallet()->operations`,
 * scope `wallet:operation:read`).
 */
final class WalletOperations
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * One page of operations (the answer is `{operations, nextCursor}`).
     *
     * @param array<string,mixed> $filters endUserId, walletAccountId, status, operationType,
     *   destinationWalletAccountId, limit, cursor.
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function list(array $filters = []): WalletPage
    {
        return new WalletPage($this->invoker->call('listOperations', [], null, null, $filters));
    }

    /**
     * @param array<string,mixed> $filters See {@see list()}.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterate(array $filters = []): Generator
    {
        return WalletPage::paginate(fn (array $page): WalletPage => $this->list($page), $filters);
    }

    /**
     * @return array<string,mixed> `{operation, history}`
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function get(string $operationId): array
    {
        return Envelope::data($this->invoker->call('getOperation', ['operationId' => $operationId]));
    }

    /**
     * The receipt data of a completed operation.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function getReceipt(string $operationId): array
    {
        return Envelope::data($this->invoker->call('getOperationReceipt', ['operationId' => $operationId]));
    }
}
