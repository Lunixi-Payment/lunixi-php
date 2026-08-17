<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Identity;

use Lunixi\Sdk\ApiClient;

/**
 * Server-side identity operations against the gateway (`/api/v1/identity/*`).
 * Bearer auth only (the browser-side device-fingerprint SDK uses a publishable
 * key against `/api/v1/identity/sdk/init`, separate from this client).
 *
 *   analyzeSync   POST /identity/live-sessions/analyze-sync   → decision + traceId
 *   accountLinks  GET  /identity/live-sessions/{traceId}/account-links → linked accounts
 *   listSessions  GET  /identity/live-sessions                (filter by device/fingerprint)
 *
 * Typical WP flow: the browser SDK collects the fingerprint and produces a
 * traceId; the WP backend calls accountLinks(traceId) and blocks/flags when more
 * than one account shares the device ("one person, many accounts").
 */
final class IdentityClient
{
    private const BASE = '/api/v1/identity';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    public function analyzeSync(AnalyzeRequest $request): IdentityDecision
    {
        $response = $this->api->request('POST', self::BASE . '/live-sessions/analyze-sync', $request->toArray());

        return new IdentityDecision(self::dataOf($response));
    }

    /** Async variant of analyzeSync: queues analysis, returns trace immediately. */
    public function analyze(AnalyzeRequest $request): IdentityDecision
    {
        $response = $this->api->request('POST', self::BASE . '/live-sessions/analyze', $request->toArray());

        return new IdentityDecision(self::dataOf($response));
    }

    /** The linked-account graph for a session (multi-account detection). */
    public function accountLinks(string $traceId): AccountLinks
    {
        $response = $this->api->request('GET', self::BASE . '/live-sessions/' . rawurlencode($traceId) . '/account-links');

        return new AccountLinks($response);
    }

    /**
     * One persisted live analysis by trace id.
     *
     * @return array<string,mixed>
     */
    public function getSession(string $traceId): array
    {
        return $this->api->request('GET', self::BASE . '/live-sessions/' . rawurlencode($traceId));
    }

    /**
     * Recent sessions linked to the same device-identity cluster as this trace.
     *
     * @return array<string,mixed>
     */
    public function relatedSessions(string $traceId, ?int $limit = null): array
    {
        $query = $limit !== null ? ['limit' => $limit] : [];

        return $this->api->request('GET', self::BASE . '/live-sessions/' . rawurlencode($traceId) . '/related-sessions', null, ['query' => $query]);
    }

    /**
     * Session summary + recorded lifecycle events (forensics).
     *
     * @return array<string,mixed>
     */
    public function timeline(string $traceId): array
    {
        return $this->api->request('GET', self::BASE . '/live-sessions/' . rawurlencode($traceId) . '/timeline');
    }

    /**
     * Source domains ranked by repeated risky activity (fraud reporting).
     *
     * @param array{days?:int, limit?:int} $filters
     * @return array<string,mixed>
     */
    public function topSources(array $filters = []): array
    {
        $query = [];
        foreach (['days', 'limit'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        return $this->api->request('GET', self::BASE . '/live-sessions/top-sources', null, ['query' => $query]);
    }

    /**
     * @param array{fingerprintId?:string, deviceId?:string, paymentId?:string, limit?:int} $filters
     * @return array<int,array<string,mixed>> Raw session rows.
     */
    public function listSessions(array $filters = []): array
    {
        $query = [];
        foreach (['fingerprintId', 'deviceId', 'paymentId', 'limit'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }
        $response = $this->api->request('GET', self::BASE . '/live-sessions', null, ['query' => $query]);

        $rows = $response['data'] ?? ($response['items'] ?? []);
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
    }
}
