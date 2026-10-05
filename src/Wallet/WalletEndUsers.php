<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Generator;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Wallet end users (`$lunixi->wallet()->endUsers`). Reads need
 * `wallet:enduser:read`; changes `wallet:enduser:manage`; the KYC level PUT
 * `wallet:enduser:kyc-override`; limits `wallet:limit:read`.
 */
final class WalletEndUsers
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * Creates an end user and their default wallet.
     *
     * @param array<string,mixed> $request programId, accountType (WalletAccountType), phone; optional
     *   externalCustomerId, kycRef, kycLevelCache, displayName, corporateAccountId, defaultCurrency.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function create(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('createEndUser', [], $request, $idempotencyKey));
    }

    /**
     * One page of end users.
     *
     * @param array<string,mixed> $filters programId, accountType, status (WalletEndUserStatus),
     *   corporateAccountId, merchantCategoryState, limit, cursor.
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function list(array $filters = []): WalletPage
    {
        return new WalletPage($this->invoker->call('listEndUsers', [], null, null, $filters));
    }

    /**
     * Every end user matching the filters, following the cursors.
     *
     * @param array<string,mixed> $filters See {@see list()}.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterate(array $filters = []): Generator
    {
        return WalletPage::paginate(fn (array $page): WalletPage => $this->list($page), $filters);
    }

    /**
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException 404 `WALLET_NOT_FOUND` for an end user of another merchant.
     */
    public function get(string $endUserId): array
    {
        return Envelope::data($this->invoker->call('getEndUser', ['endUserId' => $endUserId]));
    }

    /**
     * Finds an end user by the merchant's own customer id.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function getByExternalId(string $externalCustomerId, ?string $programId = null): array
    {
        return Envelope::data($this->invoker->call(
            'getEndUserByExternalId',
            ['externalCustomerId' => $externalCustomerId],
            null,
            null,
            ['programId' => $programId]
        ));
    }

    /**
     * Freezes the end user: no money moves until they are unfrozen.
     *
     * @param array{reason?:string} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function freeze(string $endUserId, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('freezeEndUser', ['endUserId' => $endUserId], $request, $idempotencyKey));
    }

    /**
     * Balances of every account of the end user, in minor-unit strings.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function getBalances(string $endUserId): array
    {
        return Envelope::data($this->invoker->call('getBalances', ['endUserId' => $endUserId]));
    }

    /**
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function getKycLevel(string $endUserId): array
    {
        return Envelope::data($this->invoker->call('getKycLevel', ['endUserId' => $endUserId]));
    }

    /**
     * Records the KYC level from your own verification (`sourceRef` = your
     * KYC session id). Needs `wallet:enduser:kyc-override`.
     *
     * @param array<string,mixed> $request kycLevel (WalletKycLevel), sourceRef; optional externalCustomerId,
     *   programId, reason, effectiveAt, expectedCurrentLevel.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function updateKycLevel(string $endUserId, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('updateKycLevel', ['endUserId' => $endUserId], $request, $idempotencyKey));
    }

    /**
     * Limit usage per operation type; `operationType` and `currency` narrow the answer.
     *
     * @param array{operationType?:string, currency?:string} $filters
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function getLimits(string $endUserId, array $filters = []): array
    {
        return Envelope::data($this->invoker->call('getLimits', ['endUserId' => $endUserId], null, null, $filters));
    }

    /**
     * Opens another wallet for the end user.
     *
     * @param array{name?:string, currencies?:string[]} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function createWallet(string $endUserId, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('createWallet', ['endUserId' => $endUserId], $request, $idempotencyKey));
    }
}
