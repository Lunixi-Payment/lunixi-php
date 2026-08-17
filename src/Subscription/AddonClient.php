<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Subscription add-ons. Bearer. Returns decoded gateway responses.
 *
 *   create/list/update/deactivate  /api/v1/subscriptions/addons
 */
final class AddonClient
{
    private const BASE = '/api/v1/subscriptions/addons';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /** @param array<string,mixed> $addon @return array<string,mixed> */
    public function create(array $addon): array
    {
        return $this->api->request('POST', self::BASE, $addon);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function list(array $filters = []): array
    {
        return $this->api->request('GET', self::BASE, null, ['query' => Helpers::query($filters)]);
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(string $addonId, array $changes): array
    {
        return $this->api->request('PATCH', self::BASE . '/' . rawurlencode($addonId), $changes);
    }

    /** @return array<string,mixed> */
    public function deactivate(string $addonId): array
    {
        return $this->api->request('DELETE', self::BASE . '/' . rawurlencode($addonId));
    }
}
