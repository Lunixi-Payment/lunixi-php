<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Http\Envelope;

/**
 * Sanctions screening against the gateway (`/api/v1/sanction/*`).
 *
 * Calls carry the access token minted from the API key (no per-request
 * signature). The key needs the `SANCTION_SERVICE` product and
 * `sanction:view_results`. The routes of {@see SanctionRoutes::ROUTES} (the
 * same table as the Node SDK's `SANCTION_ROUTES`) are checked before sending.
 *
 * Screening returns matches, not verdicts: read `isClear()` on the result,
 * which is true only with complete coverage. A screening that cannot run
 * (no list publication can be pinned) is answered 503 and throws an
 * ApiException; it never comes back as an empty result.
 *
 * Screening:
 *   screenName                  POST /screening/name                      → ScreenResult
 *   screenWallet                POST /screening/wallet                    → WalletScreenResult
 *   screenAccount               POST /screening/account                   → ScreenOutcome
 *   screenTransaction           POST /screening/transaction               → ScreenOutcome
 *   screenPublicProcurementBan  POST /intelligence/public-procurement-ban → ProcurementBanScreenResult
 *   screenAdverseMedia          POST /intelligence/adverse-media          → ScreenOutcome
 *   evaluateBehavioralAlert     POST /intelligence/behavioral-alert
 *   listScreeningHistory        GET  /screening/history                   → SanctionPage
 * Ongoing monitoring:
 *   registerMonitoring          POST /monitoring/register
 *   listMonitoringAlerts        GET  /monitoring/alerts                   → SanctionPage
 *   listMonitoredEntities       GET  /monitoring/entities                 → SanctionPage
 *   listMonitoringMatches       GET  /monitoring/matches                  → SanctionPage
 *   getDatasetFreshness         GET  /monitoring/dataset-freshness
 * Entity intelligence:
 *   searchEntities              GET  /intelligence/entity-search
 *   getEntityProfile            GET  /intelligence/entity-profiles/{id}
 *   getEntityGraph              GET  /intelligence/entity-graph/{id}
 *   getIndirectExposure         GET  /intelligence/indirect-exposure/{id}
 *   getOwnershipAggregation     GET  /intelligence/ownership-aggregation/{id}
 * Deny list (read):
 *   listBlacklistEntries        GET  /blacklist                           → SanctionPage
 *
 * The analyst, deny-list write and source-sync methods at the end of this
 * class call operator routes. They need `sanction:adjudicate`,
 * `sanction:assign_case`, `sanction:add_list_entry` or `sanction:source_sync`
 * / `sanction:override`; the sanction plan does not give an API key the first
 * three, so with an API key those calls are answered 403
 * `auth.permission.forbidden`.
 */
final class SanctionClient
{
    private const BASE = '/api/v1/sanction';

    private ApiClient $api;

    private SanctionInvoker $invoker;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
        $this->invoker = new SanctionInvoker($api);
    }

    // ── Screening ────────────────────────────────────────────────────────────

    public function screenName(ScreenNameRequest $request): ScreenResult
    {
        return new ScreenResult($this->invoker->call('screenName', [], $request->toArray()));
    }

    /**
     * Screens a crypto wallet address: exact match against the addresses the
     * lists of the active publication carry, plus your own wallet list.
     *
     * @param array{mode?:string, customerReference?:string} $opts mode: {@see WalletScreenMode}
     */
    public function screenWallet(string $walletAddress, string $chain = '', array $opts = []): WalletScreenResult
    {
        $body = array_merge(['walletAddress' => $walletAddress, 'chain' => $chain === '' ? null : $chain], $opts);

        return new WalletScreenResult($this->invoker->call('screenWallet', [], $body));
    }

    /**
     * Screens a bank account / IBAN against your own deny list and account
     * risk data. Sanctions lists publish no IBANs: screen the holder's name
     * with {@see screenName()} as well.
     *
     * @param array{providerCode?:string, productChannel?:string, customerReference?:string} $opts
     */
    public function screenAccount(string $accountValue, string $accountScheme, array $opts = []): ScreenOutcome
    {
        $body = array_merge(['accountValue' => $accountValue, 'accountScheme' => $accountScheme], $opts);

        return new ScreenOutcome($this->invoker->call('screenAccount', [], $body));
    }

    /**
     * Transaction-level screening: every party and account against one list
     * publication. At least one party or account.
     *
     * @param array<string,mixed> $request transactionType, direction, amount, currency,
     *   channel, occurredAt, parties[] (name, role, subjectType, countryCode, customerId,
     *   dateOfBirth, nationalId), accounts[] (accountType, value, role, providerCode, chain),
     *   metadataJson
     */
    public function screenTransaction(array $request): ScreenOutcome
    {
        return new ScreenOutcome($this->invoker->call('screenTransaction', [], $request));
    }

    /**
     * Adverse-media screening for a person/company. Runs only when your
     * adverse-media policy is enabled.
     *
     * @param array<string,mixed> $request entityName, entityType, tckn, vkn, countryCode,
     *   limit, onlyActive, customerReference, productChannel
     */
    public function screenAdverseMedia(array $request): ScreenOutcome
    {
        return new ScreenOutcome($this->invoker->call('screenAdverseMedia', [], $request));
    }

    /**
     * Public-procurement-ban (debarment) screening by name and TCKN/VKN. At
     * least one of entityName, tckn, vkn.
     *
     * @param array<string,mixed> $request entityName, tckn, vkn, entityType, onlyActive,
     *   limit, customerReference, productChannel
     */
    public function screenPublicProcurementBan(array $request): ProcurementBanScreenResult
    {
        return new ProcurementBanScreenResult($this->invoker->call('screenPublicProcurementBan', [], $request));
    }

    /**
     * Behavioural-risk alert evaluation.
     *
     * @param array<string,mixed> $request subjectReference, customerReference, productChannel,
     *   velocityScore, unusualPattern, countryRiskLevel, metadataJson
     * @return array<string,mixed> The response envelope.
     */
    public function evaluateBehavioralAlert(array $request): array
    {
        return $this->invoker->call('evaluateBehavioralAlert', [], $request);
    }

    /**
     * Earlier screenings (audit trail). {@see SanctionPage::raw()} also
     * carries the counters runsToday, reviewDecisions, blockedCount and
     * avgLatencyMs.
     *
     * @param array{limit?:int, cursor?:string} $filters
     */
    public function listScreeningHistory(array $filters = []): SanctionPage
    {
        return new SanctionPage($this->invoker->call('listScreeningHistory', [], null, $filters));
    }

    /**
     * @param array{limit?:int} $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterateScreeningHistory(array $filters = []): \Generator
    {
        return SanctionPage::paginate(fn (array $page): SanctionPage => $this->listScreeningHistory($page), $filters);
    }

    /**
     * First page of the screening history, as rows. Use
     * {@see listScreeningHistory()} to page further.
     *
     * @param array{limit?:int, cursor?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function screeningHistory(array $filters = []): array
    {
        return $this->listScreeningHistory($filters)->items();
    }

    // ── Ongoing monitoring ───────────────────────────────────────────────────

    /**
     * Registers an entity for ongoing monitoring; returns the monitored-entity id.
     *
     * @param array<string,mixed> $profile name/dateOfBirth/country/identifiers…
     * @param array{frequency?:string, riskDomainScope?:string[]} $options
     */
    public function registerMonitoring(
        string $externalCustomerId,
        array $profile,
        string $subjectType = SubjectType::PERSON,
        array $options = []
    ): string {
        $body = array_merge([
            'externalCustomerId' => $externalCustomerId,
            'subjectType' => $subjectType,
            'profileJson' => (string) json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], $options);
        $response = $this->invoker->call('registerMonitoring', [], $body);

        return SanctionValues::string(Envelope::data($response), 'monitoredEntityId');
    }

    /**
     * @param array{status?:string, limit?:int, cursor?:string, includeTotal?:bool} $filters
     */
    public function listMonitoringAlerts(array $filters = []): SanctionPage
    {
        return new SanctionPage($this->invoker->call('listMonitoringAlerts', [], null, $filters));
    }

    /**
     * @param array{status?:string, limit?:int} $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterateMonitoringAlerts(array $filters = []): \Generator
    {
        return SanctionPage::paginate(fn (array $page): SanctionPage => $this->listMonitoringAlerts($page), $filters);
    }

    /**
     * First page of monitoring alerts, as rows.
     *
     * @param array{status?:string, limit?:int, cursor?:string} $filters
     * @return array<int,array<string,mixed>> Raw alert items.
     */
    public function monitoringAlerts(array $filters = []): array
    {
        return $this->listMonitoringAlerts($filters)->items();
    }

    /**
     * Monitored subjects; the gateway lists active ones unless `activeOnly` is false.
     *
     * @param array{activeOnly?:bool, limit?:int, cursor?:string, includeTotal?:bool} $filters
     */
    public function listMonitoredEntities(array $filters = []): SanctionPage
    {
        return new SanctionPage($this->invoker->call('listMonitoredEntities', [], null, $filters));
    }

    /**
     * @param array{activeOnly?:bool, limit?:int} $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterateMonitoredEntities(array $filters = []): \Generator
    {
        return SanctionPage::paginate(fn (array $page): SanctionPage => $this->listMonitoredEntities($page), $filters);
    }

    /**
     * First page of monitored entities, as rows.
     *
     * @param array{activeOnly?:bool, limit?:int, cursor?:string} $filters
     * @return array<int,array<string,mixed>> Raw monitored-entity items.
     */
    public function monitoredEntities(array $filters = []): array
    {
        return $this->listMonitoredEntities($filters)->items();
    }

    /**
     * Matches raised by monitoring.
     *
     * @param array{status?:string, limit?:int, cursor?:string} $filters
     */
    public function listMonitoringMatches(array $filters = []): SanctionPage
    {
        return new SanctionPage($this->invoker->call('listMonitoringMatches', [], null, $filters));
    }

    /**
     * @param array{status?:string, limit?:int} $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterateMonitoringMatches(array $filters = []): \Generator
    {
        return SanctionPage::paginate(fn (array $page): SanctionPage => $this->listMonitoringMatches($page), $filters);
    }

    /**
     * Freshness of the source lists: `['manifest' => …]`. A manifest that
     * could not be produced is answered as a failure and throws.
     *
     * @return array<string,mixed>
     */
    public function getDatasetFreshness(): array
    {
        return Envelope::data($this->invoker->call('getDatasetFreshness'));
    }

    // ── Entity intelligence ──────────────────────────────────────────────────

    /**
     * Entity search by name.
     *
     * @return array<string,mixed> `items` and the search metadata.
     */
    public function searchEntities(string $query, ?int $limit = null): array
    {
        return Envelope::data($this->invoker->call('searchEntities', [], null, ['q' => $query, 'limit' => $limit]));
    }

    /**
     * Resolved entity risk profile (names, identifiers, ownership
     * relationships). With `$screeningRequestId` the entity is read on that
     * screening's pinned publication.
     *
     * @return array<string,mixed>
     */
    public function getEntityProfile(string $entityId, ?string $screeningRequestId = null): array
    {
        return Envelope::data($this->entityProfileResponse($entityId, $screeningRequestId));
    }

    /**
     * Same as {@see getEntityProfile()}, returning the whole envelope.
     *
     * @return array<string,mixed>
     */
    public function entityProfile(string $entityId, ?string $screeningRequestId = null): array
    {
        return $this->entityProfileResponse($entityId, $screeningRequestId);
    }

    /**
     * Relationship graph of an entity (`depth` 1–3).
     *
     * @return array<string,mixed> nodes, edges…
     */
    public function getEntityGraph(string $entityId, ?int $depth = null): array
    {
        return Envelope::data($this->invoker->call('getEntityGraph', ['entityId' => $entityId], null, ['depth' => $depth]));
    }

    /**
     * Indirect-exposure paths from an entity to sanctioned parties.
     * `$entityIds`: the member records of a merged match.
     *
     * @param string[] $entityIds
     * @return array<string,mixed>
     */
    public function getIndirectExposure(string $entityId, ?int $maxDepth = null, array $entityIds = []): array
    {
        return Envelope::data($this->indirectExposureResponse($entityId, $maxDepth, $entityIds));
    }

    /**
     * Same as {@see getIndirectExposure()}, returning the whole envelope.
     *
     * @param string[] $entityIds
     * @return array<string,mixed>
     */
    public function indirectExposure(string $entityId, ?int $maxDepth = null, array $entityIds = []): array
    {
        return $this->indirectExposureResponse($entityId, $maxDepth, $entityIds);
    }

    /**
     * Ownership aggregation (50% rule). `$entityIds`: the member records of a
     * merged match.
     *
     * @param string[] $entityIds
     * @return array<string,mixed> paths…
     */
    public function getOwnershipAggregation(string $entityId, ?int $depth = null, array $entityIds = []): array
    {
        return Envelope::data($this->invoker->call(
            'getOwnershipAggregation',
            ['entityId' => $entityId],
            null,
            ['depth' => $depth, 'entityIds' => $entityIds]
        ));
    }

    // ── Deny list ────────────────────────────────────────────────────────────

    /**
     * Your own deny-list entries.
     *
     * @param array{limit?:int, cursor?:string} $filters
     */
    public function listBlacklistEntries(array $filters = []): SanctionPage
    {
        return new SanctionPage($this->invoker->call('listBlacklistEntries', [], null, $filters));
    }

    /**
     * @param array{limit?:int} $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterateBlacklistEntries(array $filters = []): \Generator
    {
        return SanctionPage::paginate(fn (array $page): SanctionPage => $this->listBlacklistEntries($page), $filters);
    }

    /**
     * First page of deny-list entries, as rows.
     *
     * @param array{limit?:int, cursor?:string} $filters
     * @return array<int,array<string,mixed>> Raw blacklist items.
     */
    public function listBlacklist(array $filters = []): array
    {
        return $this->listBlacklistEntries($filters)->items();
    }

    /**
     * Adds an entry to the merchant's internal deny list
     * (`POST /blacklist`). Needs `sanction:add_list_entry`, which an API key
     * does not carry: with an API key the gateway answers 403.
     *
     * @param array<string,mixed> $extra accountValue, accountScheme, walletAddress, chain, reason, addedBy…
     * @return string The created entity id.
     */
    public function addBlacklist(string $type, string $name, array $extra = []): string
    {
        $body = array_merge(['type' => $type, 'name' => $name], $extra);
        $response = $this->api->request('POST', self::BASE . '/blacklist', $body);

        return SanctionValues::string(Envelope::data($response), 'entityId');
    }

    // ── Analyst workbench (operator routes) ──────────────────────────────────
    // The queues need `sanction:view_results`; the decisions need
    // `sanction:adjudicate` and the assignment `sanction:assign_case`, which an
    // API key does not carry (403 with an API key).

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

    // ── Operational data-source sync (compliance operator) ───────────────────
    // `sanction:source_sync` or `sanction:override`. The public-procurement-ban
    // sync route was retired by the gateway; see the 0.23.0 changelog.

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

    /** @return array<string,mixed> */
    private function entityProfileResponse(string $entityId, ?string $screeningRequestId): array
    {
        return $this->invoker->call('getEntityProfile', ['entityId' => $entityId], null, ['screeningRequestId' => $screeningRequestId]);
    }

    /**
     * @param string[] $entityIds
     * @return array<string,mixed>
     */
    private function indirectExposureResponse(string $entityId, ?int $maxDepth, array $entityIds): array
    {
        return $this->invoker->call(
            'getIndirectExposure',
            ['entityId' => $entityId],
            null,
            ['maxDepth' => $maxDepth, 'entityIds' => $entityIds]
        );
    }

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
        // Zarf okuma tek kaynakta ({@see Envelope}): gateway yükü `data`
        // altına sarıyor; koleksiyon anahtarı uca göre değişebiliyor.
        return Envelope::items($response);
    }
}
