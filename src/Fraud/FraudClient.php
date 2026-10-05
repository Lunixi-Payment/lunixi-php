<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

use Lunixi\Sdk\ApiClient;

/**
 * Server-side fraud operations against the customer fraud surface.
 * Bearer auth only (no Ed25519 step-up).
 *
 *   evaluate       POST /api/v1/fraud/decisions/evaluate     → decision + score + reasons
 *   decisionLogs   GET  /api/v1/fraud/decision-logs          (filter by paymentId, …)
 *   decisionLog    GET  /api/v1/fraud/decision-logs/{traceId}
 *
 * Event ingest lives on {@see FraudEventsClient}, reachable as `$fraud->events`:
 *
 *   events->record       POST /api/v1/fraud/events/transactions
 *   events->recordBatch  POST /api/v1/fraud/events/transactions/batch
 *
 * Panel routes (`/api/v1/fraud/admin/*`, `/api/v1/fraud/flows/*`) are NOT part of
 * the customer API surface and cannot be called with an API key — the gateway
 * answers `403 FRAUD_PANEL_SURFACE_NOT_MACHINE_CALLABLE`.
 *
 * NOTE: for payments made through the Lunixi gateway, fraud already runs
 * automatically inside authorization — use decisionLogs(paymentId:…) to read the
 * decision for an order. evaluate() is for screening your OWN events (sign-up,
 * login, custom forms) before you act on them.
 */
final class FraudClient
{
    /**
     * 🔴 `/api/v1` ONEKI ZORUNLU. Bu sabit eskiden `/fraud` idi ve SDK'nin fraud
     *    yuzeyinin TAMAMI 404 doneriyordu: gateway `setGlobalPrefix('api/v1')`
     *    uyguluyor ve exclude listesinde fraud YOK. Canli olcum (2026-09-25):
     *      POST /fraud/decisions/evaluate         -> 404
     *      POST /api/v1/fraud/decisions/evaluate  -> 401
     *    Diger 15 client zaten `/api/v1/...` kullaniyordu; yalniz fraud kaymisti.
     */
    private const BASE = '/api/v1/fraud';

    private ApiClient $api;

    /**
     * Transaction event ingest. Separate sub-client because those two routes are
     * signed PER REQUEST (Ed25519 step-up) while everything on this class is
     * bearer-only — keeping them on one object would blur which calls need the
     * signing key configured.
     */
    public FraudEventsClient $events;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
        $this->events = new FraudEventsClient($api);
    }

    public function evaluate(EvaluateRequest $request): FraudDecision
    {
        $response = $this->api->request('POST', self::BASE . '/decisions/evaluate', $request->toArray());

        return new FraudDecision(self::dataOf($response));
    }

    /**
     * Lists decision logs. Paging is cursor-based (platform pagination standard):
     * pass the previous response's `pageInfo.nextCursor` back as `cursor`.
     *
     * @param array{paymentId?:string, decision?:string, integrationMode?:string, productContext?:string, limit?:int, cursor?:string} $filters
     */
    public function decisionLogs(array $filters = []): FraudDecisionList
    {
        $query = [];
        foreach (['paymentId', 'decision', 'integrationMode', 'productContext', 'limit', 'cursor'] as $key) {
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

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }
}
