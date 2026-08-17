<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Subscription catalog products (groups of plans). Bearer. Methods return the
 * decoded gateway response (raw arrays) — the catalog resources don't yet have
 * typed SDK models.
 *
 *   create/list/get/update/archive  /api/v1/subscriptions/products
 *   translations                    /products/{id}/translations[/{locale}]
 */
final class ProductClient
{
    private const BASE = '/api/v1/subscriptions/products';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    public function create(array $product): array
    {
        return $this->api->request('POST', self::BASE, $product);
    }

    /** @param array{limit?:int, cursor?:string, active?:bool} $filters @return array<string,mixed> */
    public function list(array $filters = []): array
    {
        return $this->api->request('GET', self::BASE, null, ['query' => Helpers::query($filters)]);
    }

    /** @return array<string,mixed> */
    public function get(string $productId): array
    {
        return $this->api->request('GET', self::BASE . '/' . rawurlencode($productId));
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(string $productId, array $changes): array
    {
        return $this->api->request('PATCH', self::BASE . '/' . rawurlencode($productId), $changes);
    }

    /** @return array<string,mixed> */
    public function archive(string $productId): array
    {
        return $this->api->request('DELETE', self::BASE . '/' . rawurlencode($productId));
    }

    /** @return array<string,mixed> */
    public function translations(string $productId): array
    {
        return $this->api->request('GET', self::BASE . '/' . rawurlencode($productId) . '/translations');
    }

    /** @param array<string,mixed> $translation @return array<string,mixed> */
    public function upsertTranslation(string $productId, string $locale, array $translation): array
    {
        return $this->api->request('PATCH', self::BASE . '/' . rawurlencode($productId) . '/translations/' . rawurlencode($locale), $translation);
    }
}
