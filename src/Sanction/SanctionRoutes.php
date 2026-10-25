<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * The API-key sanction routes: method, path, body fields, query fields and
 * whether the answer is a screening. Same table as the Node SDK's
 * `SANCTION_ROUTES` (node/src/sanction/sanction-values.js), the single
 * source; a test keeps the two equal. Gateway source:
 * apps/api-gateway/src/sanction/sanction.controller.ts (routes, permissions)
 * and libs/proto/sanction/sanction.proto (request fields; the gateway forwards
 * the body to the sanction service, where an unknown field would be dropped
 * without an error, so the SDK refuses it first).
 *
 * Not listed, on purpose: the screening report PDF (panel session only);
 * adding a deny-list entry, the analyst decision/approval/assignment routes
 * and entity-resolution runs (permissions an API key does not carry); the
 * list-source syncs and the adverse-media policy write (compliance operator).
 */
final class SanctionRoutes
{
    private const BASE = '/api/v1/sanction';

    public const ROUTES = [
        'screenName' => [
            'method' => 'POST',
            'path' => self::BASE . '/screening/name',
            'screening' => true,
            'required' => ['name'],
            'optional' => ['subjectType', 'country', 'dateOfBirth', 'identifiers', 'riskDomains', 'mode', 'threshold', 'customerReference'],
        ],
        'screenWallet' => [
            'method' => 'POST',
            'path' => self::BASE . '/screening/wallet',
            'screening' => true,
            'required' => ['walletAddress'],
            'optional' => ['chain', 'mode', 'customerReference'],
        ],
        'screenAccount' => [
            'method' => 'POST',
            'path' => self::BASE . '/screening/account',
            'screening' => true,
            'required' => ['accountValue', 'accountScheme'],
            'optional' => ['providerCode', 'productChannel', 'customerReference'],
        ],
        'screenTransaction' => [
            'method' => 'POST',
            'path' => self::BASE . '/screening/transaction',
            'screening' => true,
            'required' => [],
            'optional' => ['transactionType', 'direction', 'amount', 'currency', 'channel', 'occurredAt', 'parties', 'accounts', 'metadataJson'],
            'anyOf' => ['parties', 'accounts'],
        ],
        'screenPublicProcurementBan' => [
            'method' => 'POST',
            'path' => self::BASE . '/intelligence/public-procurement-ban',
            'screening' => true,
            'required' => [],
            'optional' => ['entityName', 'tckn', 'vkn', 'entityType', 'onlyActive', 'limit', 'customerReference', 'productChannel'],
            'anyOf' => ['entityName', 'tckn', 'vkn'],
        ],
        'screenAdverseMedia' => [
            'method' => 'POST',
            'path' => self::BASE . '/intelligence/adverse-media',
            'screening' => true,
            'required' => [],
            'optional' => ['entityName', 'entityType', 'tckn', 'vkn', 'countryCode', 'limit', 'onlyActive', 'customerReference', 'productChannel'],
        ],
        'evaluateBehavioralAlert' => [
            'method' => 'POST',
            'path' => self::BASE . '/intelligence/behavioral-alert',
            'screening' => true,
            'required' => [],
            'optional' => ['subjectReference', 'customerReference', 'productChannel', 'velocityScore', 'unusualPattern', 'countryRiskLevel', 'metadataJson'],
        ],
        'listScreeningHistory' => [
            'method' => 'GET',
            'path' => self::BASE . '/screening/history',
            'screening' => false,
            'query' => ['limit', 'cursor'],
        ],
        'registerMonitoring' => [
            'method' => 'POST',
            'path' => self::BASE . '/monitoring/register',
            'screening' => false,
            'required' => ['externalCustomerId'],
            'optional' => ['subjectType', 'profileJson', 'frequency', 'riskDomainScope'],
        ],
        'listMonitoringAlerts' => [
            'method' => 'GET',
            'path' => self::BASE . '/monitoring/alerts',
            'screening' => false,
            'query' => ['status', 'limit', 'cursor', 'includeTotal'],
        ],
        'listMonitoredEntities' => [
            'method' => 'GET',
            'path' => self::BASE . '/monitoring/entities',
            'screening' => false,
            'query' => ['activeOnly', 'limit', 'cursor', 'includeTotal'],
        ],
        'listMonitoringMatches' => [
            'method' => 'GET',
            'path' => self::BASE . '/monitoring/matches',
            'screening' => false,
            'query' => ['status', 'limit', 'cursor'],
        ],
        'getDatasetFreshness' => [
            'method' => 'GET',
            'path' => self::BASE . '/monitoring/dataset-freshness',
            'screening' => false,
        ],
        'searchEntities' => [
            'method' => 'GET',
            'path' => self::BASE . '/intelligence/entity-search',
            'screening' => false,
            'query' => ['q', 'limit'],
        ],
        'getEntityProfile' => [
            'method' => 'GET',
            'path' => self::BASE . '/intelligence/entity-profiles/:entityId',
            'screening' => false,
            'query' => ['screeningRequestId'],
        ],
        'getEntityGraph' => [
            'method' => 'GET',
            'path' => self::BASE . '/intelligence/entity-graph/:entityId',
            'screening' => false,
            'query' => ['depth'],
        ],
        'getIndirectExposure' => [
            'method' => 'GET',
            'path' => self::BASE . '/intelligence/indirect-exposure/:entityId',
            'screening' => false,
            'query' => ['maxDepth', 'entityIds'],
        ],
        'getOwnershipAggregation' => [
            'method' => 'GET',
            'path' => self::BASE . '/intelligence/ownership-aggregation/:entityId',
            'screening' => false,
            'query' => ['depth', 'entityIds'],
        ],
        'listBlacklistEntries' => [
            'method' => 'GET',
            'path' => self::BASE . '/blacklist',
            'screening' => false,
            'query' => ['limit', 'cursor'],
        ],
    ];

    /** Fields of the nested lists, checked element by element. */
    public const NESTED_FIELDS = [
        'screenName.identifiers' => ['required' => ['type', 'value'], 'optional' => []],
        'screenTransaction.parties' => ['required' => ['name'], 'optional' => ['role', 'subjectType', 'countryCode', 'customerId', 'dateOfBirth', 'nationalId']],
        'screenTransaction.accounts' => ['required' => ['accountType', 'value'], 'optional' => ['role', 'providerCode', 'chain']],
    ];

    /** Value sets checked before sending; the sanction service refuses anything else with 400. */
    public const VALUE_SETS = [
        'screenName.riskDomains' => ['sanctions', 'pep', 'rca', 'wanted', 'regulatory', 'debarment', 'ownership', 'vessel', 'securities'],
        'screenName.mode' => ['sanctions_only', 'sanctions_pep', 'full_risk'],
        'screenName.subjectType' => ['person', 'organization'],
        'screenWallet.mode' => ['wallet_sanctions', 'wallet_risk', 'wallet_full_aml'],
        'registerMonitoring.subjectType' => ['person', 'organization'],
        'registerMonitoring.riskDomainScope' => ['sanctions', 'pep', 'rca', 'wanted', 'regulatory', 'debarment', 'ownership', 'vessel', 'securities'],
    ];

    private function __construct()
    {
    }
}
