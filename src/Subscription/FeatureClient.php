<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Subscription entitlement features. Bearer. Returns decoded gateway responses.
 *
 *   create/list/get/update/delete  /api/v1/subscriptions/features
 *   translations                   /features/{id}/translations[/{locale}]
 */
final class FeatureClient
{
    private const BASE = '/api/v1/subscriptions/features';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /** @param array<string,mixed> $feature @return array<string,mixed> */
    public function create(array $feature): array
    {
        return $this->api->request('POST', self::BASE, $feature);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function list(array $filters = []): array
    {
        return $this->api->request('GET', self::BASE, null, ['query' => Helpers::query($filters)]);
    }

    /** @return array<string,mixed> */
    public function get(string $featureId): array
    {
        return $this->api->request('GET', self::BASE . '/' . rawurlencode($featureId));
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(string $featureId, array $changes): array
    {
        return $this->api->request('PATCH', self::BASE . '/' . rawurlencode($featureId), $changes);
    }

    /** @return array<string,mixed> */
    public function delete(string $featureId): array
    {
        return $this->api->request('DELETE', self::BASE . '/' . rawurlencode($featureId));
    }

    /** @return array<string,mixed> */
    public function translations(string $featureId): array
    {
        return $this->api->request('GET', self::BASE . '/' . rawurlencode($featureId) . '/translations');
    }

    /** @param array<string,mixed> $translation @return array<string,mixed> */
    public function upsertTranslation(string $featureId, string $locale, array $translation): array
    {
        return $this->api->request('PATCH', self::BASE . '/' . rawurlencode($featureId) . '/translations/' . rawurlencode($locale), $translation);
    }
}
