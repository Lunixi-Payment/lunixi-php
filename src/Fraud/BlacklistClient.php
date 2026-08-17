<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

use Lunixi\Sdk\ApiClient;

/**
 * Fraud allow/deny list management (`/fraud/admin/blacklists`). An entry blocks
 * (or allow-lists) a value by scope + data type — e.g. an email, IP, BIN or card
 * fingerprint. Bearer auth; the gateway scopes everything to the merchant.
 */
final class BlacklistClient
{
    private const BASE = '/fraud/admin/blacklists';

    public const TYPE_EMAIL = 'email';
    public const TYPE_IP = 'ip';
    public const TYPE_BIN = 'bin';
    public const TYPE_FINGERPRINT = 'fingerprint';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * @param array{scopeType?:string, dataType?:string, status?:string, limit?:int} $filters
     * @return array<int,array<string,mixed>> The raw blacklist entries.
     */
    public function list(array $filters = []): array
    {
        $query = [];
        foreach (['scopeType', 'dataType', 'status', 'limit'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }
        $response = $this->api->request('GET', self::BASE, null, ['query' => $query]);

        return isset($response['data']) && is_array($response['data']) ? array_values($response['data']) : [];
    }

    /**
     * Adds an entry. Minimum: dataType (one of TYPE_*) + the value to block.
     *
     * @param array<string,mixed> $extra Optional fields (scopeType, description, status, source, …).
     * @return array<string,mixed> The created entry.
     */
    public function create(string $dataType, string $value, array $extra = []): array
    {
        $body = array_merge(['dataType' => $dataType, 'data' => $value], $extra);
        $response = $this->api->request('POST', self::BASE, $body);

        return isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function update(string $id, array $fields): array
    {
        $response = $this->api->request('PUT', self::BASE . '/' . rawurlencode($id), $fields);

        return isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
    }

    public function delete(string $id): void
    {
        $this->api->request('DELETE', self::BASE . '/' . rawurlencode($id));
    }
}
