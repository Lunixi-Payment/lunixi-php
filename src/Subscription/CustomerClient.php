<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Subscription customers. Upsert is keyed by the merchant's `externalId`, so the
 * same WordPress user maps to one stable customer across calls.
 */
final class CustomerClient
{
    private const BASE = '/api/v1/subscriptions/customers';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    public function upsert(UpsertCustomerRequest $request, ?string $idempotencyKey = null): Customer
    {
        $response = $this->api->request('PUT', self::BASE, $request->toArray(), [
            'idempotencyKey' => $idempotencyKey,
        ]);

        return new Customer(Helpers::dataOf($response));
    }

    public function get(string $customerId): Customer
    {
        $response = $this->api->request('GET', self::BASE . '/' . rawurlencode($customerId));

        return new Customer(Helpers::dataOf($response));
    }

    public function getByExternalId(string $externalId): Customer
    {
        $response = $this->api->request('GET', self::BASE . '/by-external/' . rawurlencode($externalId));

        return new Customer(Helpers::dataOf($response));
    }

    /**
     * @param array{search?:string, includeArchived?:bool, pageSize?:int, pageToken?:string} $filters
     * @return CursorList<Customer>
     */
    public function list(array $filters = []): CursorList
    {
        $response = $this->api->request('GET', self::BASE, null, ['query' => Helpers::query($filters)]);

        return new CursorList($response, static fn (array $row): Customer => new Customer($row));
    }

    /** @return array<string,mixed> The credit-balance `data` object. */
    public function creditBalance(string $customerId): array
    {
        $response = $this->api->request('GET', self::BASE . '/' . rawurlencode($customerId) . '/credit-balance');

        return Helpers::dataOf($response);
    }

    /**
     * Adjusts a customer's credit balance (grant/deduct).
     *
     * @param array<string,mixed> $adjustment amountMinor, currency, reason, type…
     * @return array<string,mixed>
     */
    public function adjustCredit(string $customerId, array $adjustment): array
    {
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($customerId) . '/credit-balance/adjust', $adjustment);

        return Helpers::dataOf($response);
    }

    public function archive(string $customerId): Customer
    {
        $response = $this->api->request('DELETE', self::BASE . '/' . rawurlencode($customerId));

        return new Customer(Helpers::dataOf($response));
    }
}
