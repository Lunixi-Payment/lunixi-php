<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\ApiClient;

/**
 * Server-side sanction/watchlist screening against the gateway (`/api/v1/sanction/*`).
 * Bearer auth only (no Ed25519 step-up). The WP plugin surfaces a merchant subset
 * (name screening + monitoring + deny-list); this client is the complete REST
 * client so any integration on the same gateway can reach the full surface.
 *
 * Screening:
 *   screenName          POST /sanction/screening/name          → matches (OFAC/UN/EU/PEP)
 *   screenWallet        POST /sanction/screening/wallet        → crypto wallet risk
 *   screenAccount       POST /sanction/screening/account       → bank/IBAN account risk
 *   screenTransaction   POST /sanction/screening/transaction   → tx-level decision
 *   screenAdverseMedia  POST /sanction/intelligence/adverse-media
 *   screenProcurementBan POST /sanction/intelligence/public-procurement-ban
 *   evaluateBehavioralAlert POST /sanction/intelligence/behavioral-alert
 *   screeningHistory    GET  /sanction/screening/history
 * Intelligence (entity graph):
 *   entityProfile       GET  /sanction/intelligence/entity-profiles/{id}
 *   indirectExposure    GET  /sanction/intelligence/indirect-exposure/{id}
 * Monitoring:
 *   registerMonitoring  POST /sanction/monitoring/register
 *   monitoringAlerts    GET  /sanction/monitoring/alerts
 *   monitoredEntities   GET  /sanction/monitoring/entities
 * Deny list:
 *   addBlacklist        POST /sanction/blacklist
 *   listBlacklist       GET  /sanction/blacklist
 * Analyst workbench:
 *   analystHitQueue     GET  /sanction/analyst/hit-queue
 *   analystCaseQueue    GET  /sanction/analyst/case-queue
 *   submitHitDecision   POST /sanction/analyst/hit-decisions
 *   submitCaseDecision  POST /sanction/analyst/case-decisions
 *   assignCase          POST /sanction/analyst/cases/{id}/assign
 * Operational data-source sync (platform/admin; bearer-gated):
 *   syncOfac, syncProcurementBans, syncIdentityGraph
 */
final class SanctionClient
{
    private const BASE = '/api/v1/sanction';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    public function screenName(ScreenNameRequest $request): ScreenResult
    {
        $response = $this->api->request('POST', self::BASE . '/screening/name', $request->toArray());

        return new ScreenResult($response);
    }

    /**
     * Screens a crypto wallet address against sanction/risk lists.
     *
     * @param array{mode?:string, customerReference?:string} $opts
     */
    public function screenWallet(string $walletAddress, string $chain, array $opts = []): ScreenOutcome
    {
        $body = array_merge(['walletAddress' => $walletAddress, 'chain' => $chain], $opts);

        return new ScreenOutcome($this->api->request('POST', self::BASE . '/screening/wallet', $body));
    }

    /**
     * Screens a bank account / IBAN against sanction + internal deny lists.
     *
     * @param array{providerCode?:string, productChannel?:string, customerReference?:string} $opts
     */
    public function screenAccount(string $accountValue, string $accountScheme, array $opts = []): ScreenOutcome
    {
        $body = array_merge(['accountValue' => $accountValue, 'accountScheme' => $accountScheme], $opts);

        return new ScreenOutcome($this->api->request('POST', self::BASE . '/screening/account', $body));
    }

    /**
     * Transaction-level screening (parties + accounts + amount → decision).
     *
     * @param array<string,mixed> $request transactionType, direction, amount, currency,
     *   channel, occurredAt, parties[], accounts[], metadataJson…
     */
    public function screenTransaction(array $request): ScreenOutcome
    {
        return new ScreenOutcome($this->api->request('POST', self::BASE . '/screening/transaction', $request));
    }

    /**
     * Adverse-media screening for a person/company.
     *
     * @param array<string,mixed> $request entityName, entityType, tckn, vkn, countryCode,
     *   limit, onlyActive, customerReference, productChannel…
     */
    public function screenAdverseMedia(array $request): ScreenOutcome
    {
        return new ScreenOutcome($this->api->request('POST', self::BASE . '/intelligence/adverse-media', $request));
    }

    /**
     * Public-procurement-ban screening.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function screenPublicProcurementBan(array $request): array
    {
        return $this->api->request('POST', self::BASE . '/intelligence/public-procurement-ban', $request);
    }

    /**
     * Behavioural-risk alert evaluation.
     *
     * @param array<string,mixed> $request subjectReference, customerReference, productChannel,
     *   velocityScore, unusualPattern, countryRiskLevel, metadataJson…
     * @return array<string,mixed>
     */
    public function evaluateBehavioralAlert(array $request): array
    {
        return $this->api->request('POST', self::BASE . '/intelligence/behavioral-alert', $request);
    }

    /**
     * Past screening runs (audit/history).
     *
     * @param array{limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function screeningHistory(array $filters = []): array
    {
        return $this->itemsOf($this->api->request('GET', self::BASE . '/screening/history', null, ['query' => $this->limitQuery($filters)]));
    }

    /**
     * Resolved entity risk profile (names, identifiers, ownership relationships).
     *
     * @return array<string,mixed>
     */
    public function entityProfile(string $entityId): array
    {
        return $this->api->request('GET', self::BASE . '/intelligence/entity-profiles/' . rawurlencode($entityId));
    }

    /**
     * Indirect-exposure paths from an entity to sanctioned parties.
     *
     * @return array<string,mixed>
     */
    public function indirectExposure(string $entityId, ?int $maxDepth = null): array
    {
        $query = $maxDepth !== null ? ['maxDepth' => $maxDepth] : [];

        return $this->api->request('GET', self::BASE . '/intelligence/indirect-exposure/' . rawurlencode($entityId), null, ['query' => $query]);
    }

    /**
     * Registers an entity for ongoing monitoring; returns the monitored-entity id.
     *
     * @param array<string,mixed> $profile name/dob/country/identifiers…
     */
    public function registerMonitoring(string $externalCustomerId, array $profile, string $subjectType = SubjectType::PERSON): string
    {
        $response = $this->api->request('POST', self::BASE . '/monitoring/register', [
            'externalCustomerId' => $externalCustomerId,
            'subjectType' => $subjectType,
            'profileJson' => (string) json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return (string) ($response['monitoredEntityId'] ?? '');
    }

    /**
     * @param array{status?:string, limit?:int} $filters
     * @return array<int,array<string,mixed>> Raw alert items.
     */
    public function monitoringAlerts(array $filters = []): array
    {
        $query = [];
        foreach (['status', 'limit'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }
        $response = $this->api->request('GET', self::BASE . '/monitoring/alerts', null, ['query' => $query]);

        return isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
    }

    /**
     * @param array{activeOnly?:bool, limit?:int} $filters
     * @return array<int,array<string,mixed>> Raw monitored-entity items.
     */
    public function monitoredEntities(array $filters = []): array
    {
        $query = [];
        if (isset($filters['limit']) && $filters['limit'] !== '') {
            $query['limit'] = $filters['limit'];
        }
        if (array_key_exists('activeOnly', $filters)) {
            $query['activeOnly'] = $filters['activeOnly'] ? 'true' : 'false';
        }
        $response = $this->api->request('GET', self::BASE . '/monitoring/entities', null, ['query' => $query]);

        return isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
    }

    /**
     * Adds an entry to the merchant's internal deny list.
     *
     * @param array<string,mixed> $extra accountValue, accountScheme, walletAddress, chain, reason, addedBy…
     * @return string The created entity id.
     */
    public function addBlacklist(string $type, string $name, array $extra = []): string
    {
        $body = array_merge(['type' => $type, 'name' => $name], $extra);
        $response = $this->api->request('POST', self::BASE . '/blacklist', $body);

        return (string) ($response['entityId'] ?? '');
    }

    /**
     * @param array{limit?:int} $filters
     * @return array<int,array<string,mixed>> Raw blacklist items.
     */
    public function listBlacklist(array $filters = []): array
    {
        $query = [];
        if (isset($filters['limit']) && $filters['limit'] !== '') {
            $query['limit'] = $filters['limit'];
        }
        $response = $this->api->request('GET', self::BASE . '/blacklist', null, ['query' => $query]);

        return isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
    }

    // ── Analyst workbench ────────────────────────────────────────────────────

    /**
     * The hit queue (potential matches awaiting analyst disposition).
     *
     * @param array{limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function analystHitQueue(array $filters = []): array
    {
        return $this->itemsOf($this->api->request('GET', self::BASE . '/analyst/hit-queue', null, ['query' => $this->limitQuery($filters)]));
    }

    /**
     * The case queue (escalated cases awaiting analyst decision).
     *
     * @param array{limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function analystCaseQueue(array $filters = []): array
    {
        return $this->itemsOf($this->api->request('GET', self::BASE . '/analyst/case-queue', null, ['query' => $this->limitQuery($filters)]));
    }

    /**
     * Records an analyst disposition for a hit.
     *
     * @param array<string,mixed> $request hitId, analystId, analystDisposition,
     *   overrideReason?, analystNotes?
     * @return array<string,mixed>
     */
    public function submitHitDecision(array $request): array
    {
        return $this->api->request('POST', self::BASE . '/analyst/hit-decisions', $request);
    }

    /**
     * Records an analyst decision for a case.
     *
     * @param array<string,mixed> $request caseId, analystId, decision, decisionReason?, analystNotes?
     * @return array<string,mixed>
     */
    public function submitCaseDecision(array $request): array
    {
        return $this->api->request('POST', self::BASE . '/analyst/case-decisions', $request);
    }

    /**
     * Assigns a case to an analyst.
     *
     * @return array<string,mixed>
     */
    public function assignCase(string $caseId, string $analystId): array
    {
        return $this->api->request('POST', self::BASE . '/analyst/cases/' . rawurlencode($caseId) . '/assign', ['analystId' => $analystId]);
    }

    // ── Operational data-source sync (platform/admin) ────────────────────────

    /**
     * Triggers an OFAC source refresh. Operational/platform surface.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function syncOfac(array $request = []): array
    {
        return $this->api->request('POST', self::BASE . '/intelligence/ofac/sync', $request);
    }

    /**
     * Triggers a public-procurement-ban source refresh.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function syncProcurementBans(array $request = []): array
    {
        return $this->api->request('POST', self::BASE . '/intelligence/public-procurement-ban/sync', $request);
    }

    /**
     * Triggers an identity-graph sync.
     *
     * @param array<string,mixed> $request entityId?, relationshipId?, syncMode?
     * @return array<string,mixed>
     */
    public function syncIdentityGraph(array $request = []): array
    {
        return $this->api->request('POST', self::BASE . '/intelligence/identity-graph/sync', $request);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array{limit?:int} $filters
     * @return array<string,mixed>
     */
    private function limitQuery(array $filters): array
    {
        return (isset($filters['limit']) && $filters['limit'] !== '') ? ['limit' => $filters['limit']] : [];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<int,array<string,mixed>>
     */
    private function itemsOf(array $response): array
    {
        $rows = $response['items'] ?? ($response['data'] ?? []);
        return is_array($rows) ? array_values($rows) : [];
    }
}
