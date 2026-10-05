<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Generator;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Corporate accounts and their employees (`$lunixi->wallet()->corporate`).
 */
final class WalletCorporate
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * @param array<string,mixed> $request programId, code, name, ownerEndUserId; optional fundingCurrency.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function create(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('createCorporateAccount', [], $request, $idempotencyKey));
    }

    /**
     * @param array<string,mixed> $filters programId, status (WalletCorporateAccountStatus), limit, cursor.
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function list(array $filters = []): WalletPage
    {
        return new WalletPage($this->invoker->call('listCorporateAccounts', [], null, null, $filters));
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
     * Imports up to 1000 employees at once; all or nothing (422 writes nothing).
     *
     * @param array<int,array<string,mixed>> $rows Each: phone; optional displayName, externalCustomerId, kycRef.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function importEmployees(string $corporateAccountId, array $rows, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call(
            'importCorporateEmployees',
            ['corporateAccountId' => $corporateAccountId],
            ['rows' => $rows],
            $idempotencyKey
        ));
    }

    /**
     * @param array<int,array<string,mixed>> $rows    Each: endUserId or externalCustomerId.
     * @param array{reason?:string}          $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function offboardEmployees(string $corporateAccountId, array $rows, array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call(
            'offboardCorporateEmployees',
            ['corporateAccountId' => $corporateAccountId],
            ['rows' => $rows] + $request,
            $idempotencyKey
        ));
    }
}
