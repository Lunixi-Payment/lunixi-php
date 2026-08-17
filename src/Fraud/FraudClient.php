<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

use Lunixi\Sdk\ApiClient;

/**
 * Server-side fraud operations against the gateway fraud surface (`/fraud/*`).
 * Bearer auth only (no Ed25519 step-up).
 *
 *   evaluate       POST /fraud/decisions/evaluate     → decision + score + reasons
 *   decisionLogs   GET  /fraud/decision-logs          (filter by paymentId, …)
 *   decisionLog    GET  /fraud/decision-logs/{traceId}
 *
 * NOTE: for payments made through the Lunixi gateway, fraud already runs
 * automatically inside authorization — use decisionLogs(paymentId:…) to read the
 * decision for an order. evaluate() is for screening your OWN events (sign-up,
 * login, custom forms) before you act on them.
 */
final class FraudClient
{
    private const BASE = '/fraud';

    private ApiClient $api;
    private ?BlacklistClient $blacklists = null;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    public function evaluate(EvaluateRequest $request): FraudDecision
    {
        $response = $this->api->request('POST', self::BASE . '/decisions/evaluate', $request->toArray());

        return new FraudDecision(self::dataOf($response));
    }

    /**
     * @param array{paymentId?:string, decision?:string, integrationMode?:string, productContext?:string, limit?:int} $filters
     */
    public function decisionLogs(array $filters = []): FraudDecisionList
    {
        $query = [];
        foreach (['paymentId', 'decision', 'integrationMode', 'productContext', 'limit'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        $response = $this->api->request('GET', self::BASE . '/decision-logs', null, ['query' => $query]);

        return new FraudDecisionList($response);
    }

    public function decisionLog(string $traceId): FraudDecision
    {
        $response = $this->api->request('GET', self::BASE . '/decision-logs/' . rawurlencode($traceId));

        return new FraudDecision(self::dataOf($response));
    }

    /** The most recent decision for a payment intent, or null. */
    public function latestForPayment(string $paymentId): ?FraudDecision
    {
        return $this->decisionLogs(['paymentId' => $paymentId, 'limit' => 1])->first();
    }

    /** Allow/deny list management (email, IP, BIN, card fingerprint). */
    public function blacklists(): BlacklistClient
    {
        return $this->blacklists ?? $this->blacklists = new BlacklistClient($this->api);
    }

    /**
     * Aggregated device-fingerprint risk/reputation profiles. Read-only
     * intelligence a merchant can consult server-side.
     *
     * @param array{limit?:int, search?:string} $filters
     * @return array<string,mixed>
     */
    public function deviceProfiles(array $filters = []): array
    {
        return $this->api->request('GET', self::BASE . '/admin/intelligence/device-profiles', null, ['query' => $this->listQuery($filters)]);
    }

    /**
     * Aggregated IP-address risk/reputation profiles.
     *
     * @param array{limit?:int, search?:string} $filters
     * @return array<string,mixed>
     */
    public function ipProfiles(array $filters = []): array
    {
        return $this->api->request('GET', self::BASE . '/admin/intelligence/ip-profiles', null, ['query' => $this->listQuery($filters)]);
    }

    /**
     * Aggregated identity (email/identity-key) risk/reputation profiles.
     *
     * @param array{limit?:int, search?:string} $filters
     * @return array<string,mixed>
     */
    public function identityProfiles(array $filters = []): array
    {
        return $this->api->request('GET', self::BASE . '/admin/intelligence/identity-profiles', null, ['query' => $this->listQuery($filters)]);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function listQuery(array $filters): array
    {
        $query = [];
        foreach (['limit', 'search'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }
        return $query;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }
}
