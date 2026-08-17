<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Subscription plans. Bearer auth (no step-up). Plans are templates a
 * subscription is created against.
 */
final class PlanClient
{
    private const BASE = '/api/v1/subscriptions/plans';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    public function create(CreatePlanRequest $request, ?string $idempotencyKey = null): Plan
    {
        $response = $this->api->request('POST', self::BASE, $request->toArray(), [
            'idempotencyKey' => $idempotencyKey,
        ]);

        return new Plan(Helpers::dataOf($response));
    }

    public function get(string $planId): Plan
    {
        $response = $this->api->request('GET', self::BASE . '/' . rawurlencode($planId));

        return new Plan(Helpers::dataOf($response));
    }

    /**
     * @param array{productId?:string, includeArchived?:bool, pageSize?:int, pageToken?:string} $filters
     * @return CursorList<Plan>
     */
    public function list(array $filters = []): CursorList
    {
        $response = $this->api->request('GET', self::BASE, null, ['query' => Helpers::query($filters)]);

        return new CursorList($response, static fn (array $row): Plan => new Plan($row));
    }

    /**
     * Updates a plan (price, billing, metadata…).
     *
     * @param array<string,mixed> $changes
     */
    public function update(string $planId, array $changes): Plan
    {
        $response = $this->api->request('PATCH', self::BASE . '/' . rawurlencode($planId), $changes);

        return new Plan(Helpers::dataOf($response));
    }

    public function archive(string $planId): Plan
    {
        $response = $this->api->request('DELETE', self::BASE . '/' . rawurlencode($planId));

        return new Plan(Helpers::dataOf($response));
    }

    /** @return array<string,mixed> */
    public function translations(string $planId): array
    {
        return $this->api->request('GET', self::BASE . '/' . rawurlencode($planId) . '/translations');
    }

    /** @param array<string,mixed> $translation @return array<string,mixed> */
    public function upsertTranslation(string $planId, string $locale, array $translation): array
    {
        return $this->api->request('PATCH', self::BASE . '/' . rawurlencode($planId) . '/translations/' . rawurlencode($locale), $translation);
    }
}
