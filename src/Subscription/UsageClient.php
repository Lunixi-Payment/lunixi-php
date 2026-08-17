<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Metered usage + entitlement checks. Bearer. Returns decoded gateway responses.
 *
 *   record           POST /api/v1/subscriptions/usage/record
 *   checkEntitlement POST /api/v1/subscriptions/usage/check-entitlement
 */
final class UsageClient
{
    private const BASE = '/api/v1/subscriptions/usage';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Records a metered-usage event.
     *
     * @param array<string,mixed> $usage customerId/subscriptionId, featureKey,
     *   quantity, timestamp?, idempotencyKey?…
     * @return array<string,mixed>
     */
    public function record(array $usage): array
    {
        return $this->api->request('POST', self::BASE . '/record', $usage);
    }

    /**
     * Checks whether a customer is entitled / has quota remaining.
     *
     * @param array<string,mixed> $check customerId/subscriptionId, featureKey, requested?…
     * @return array<string,mixed>
     */
    public function checkEntitlement(array $check): array
    {
        return $this->api->request('POST', self::BASE . '/check-entitlement', $check);
    }
}
