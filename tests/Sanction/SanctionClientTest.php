<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Sanction;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Sanction\CoverageStatus;
use Lunixi\Sdk\Sanction\RiskDomain;
use Lunixi\Sdk\Sanction\SanctionClient;
use Lunixi\Sdk\Sanction\SanctionRoutes;
use Lunixi\Sdk\Sanction\ScreenMode;
use Lunixi\Sdk\Sanction\ScreenNameRequest;
use Lunixi\Sdk\Sanction\SubjectType;
use Lunixi\Sdk\Sanction\WalletScreenMode;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Contract source: nest-payment-gateway apps/api-gateway/src/sanction/sanction.controller.ts,
 * sanction.service.ts, libs/proto/sanction/sanction.proto and the gateway envelope
 * (libs/common/src/interceptors/gateway-format.interceptor.ts): the screening fields are
 * under `data`; the top-level `requestId` is the HTTP request's. Names are fictional.
 */
final class SanctionClientTest extends TestCase
{
    /** @return array{0:SanctionClient,1:FakeHttpClient} */
    private function make(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration([
            'baseUrl' => 'https://gw.example.com',
            'keyId' => 'kid_1',
            'privateKey' => $keys['privateKey'],
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $store->set($config->tokenCacheKey(), 'bearer_x', 3600);
        $tokens = new TokenManager($config, $signer, $http, $store);

        return [new SanctionClient(new ApiClient($config, $signer, $tokens, $http)), $http];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function envelope(array $data, array $extra = []): array
    {
        return array_merge(['status' => 'success', 'code' => 'sanction.screen_name.completed', 'data' => $data, 'statusCode' => 200, 'requestId' => 'req_http_1'], $extra);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function nameData(array $overrides = []): array
    {
        return array_merge([
            'latencyMs' => 42,
            'screeningRequestId' => 'scr_1',
            'publicationId' => 'pub_1',
            'publicationRevision' => 34,
            'coverageStatus' => 'COMPLETE',
            'evidenceRef' => 'ev_1',
            'screenedRiskDomains' => ['sanctions', 'pep'],
            'uncoveredRiskDomains' => [],
            'matches' => [],
            'datasetFreshnessJson' => '{"OFAC":"2026-10-18"}',
        ], $overrides);
    }

    public function testScreenNameReadsMatchesFromDataWithLists(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::nameData([
            'matches' => [[
                'hitId' => 'h1', 'entityId' => 'e1', 'matchedName' => 'Ayla Kurgu', 'listCode' => 'OFAC SDN',
                'sourceListName' => 'OFAC SDN', 'score' => 0.92, 'hitType' => 'probable', 'riskDomains' => ['sanctions'],
                'lists' => [
                    ['datasetCode' => 'OFAC', 'displayName' => 'OFAC SDN', 'datasetVersionId' => 'v9', 'programs' => ['SDGT'], 'entityIds' => ['e1'], 'listings' => []],
                    ['datasetCode' => 'UN', 'displayName' => 'UN SC', 'datasetVersionId' => 'v3', 'entityIds' => ['e7']],
                ],
            ]],
        ])));

        $req = (new ScreenNameRequest('Ayla Kurgu'))
            ->withCountry('TR')
            ->withSubjectType(SubjectType::PERSON)
            ->withMode(ScreenMode::SANCTIONS_PEP)
            ->withRiskDomains([RiskDomain::SANCTIONS, RiskDomain::PEP])
            ->withThreshold(0.85)
            ->withCustomerReference('42');
        $result = $sanction->screenName($req);

        $this->assertTrue($result->hasMatches());
        $this->assertFalse($result->isClear());
        $this->assertSame('scr_1', $result->screeningRequestId());
        $this->assertSame('req_http_1', $result->requestId(), 'the envelope requestId is the HTTP request id');
        $this->assertSame('pub_1', $result->publicationId());
        $this->assertSame(34, $result->publicationRevision());
        $this->assertSame(CoverageStatus::COMPLETE, $result->coverageStatus());
        $this->assertSame('ev_1', $result->evidenceRef());
        $this->assertSame(['OFAC' => '2026-10-18'], $result->datasetFreshness());
        $match = $result->matches()[0];
        $this->assertSame('e1', $match->entityId());
        $this->assertSame('OFAC SDN', $match->sourceListName());
        $this->assertSame(['sanctions'], $match->riskDomains());
        $this->assertCount(2, $match->lists());
        $this->assertSame('UN', $match->lists()[1]->datasetCode());
        $this->assertSame(['e7'], $match->lists()[1]->entityIds());
        $this->assertSame(0.92, $result->topScore());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/sanction/screening/name', $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'sanction is bearer-only');
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('Ayla Kurgu', $body['name']);
        $this->assertSame(['sanctions', 'pep'], $body['riskDomains']);
        $this->assertSame('sanctions_pep', $body['mode']);
        $this->assertSame('42', $body['customerReference']);
    }

    public function testNoMatchIsClearOnlyWithCompleteCoverage(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::nameData()));
        $http->pushJson(200, self::envelope(self::nameData(['coverageStatus' => 'PARTIAL', 'uncoveredRiskDomains' => ['vessel']])));
        $http->pushJson(200, self::envelope(self::nameData(['coverageStatus' => 'DEGRADED'])));
        $http->pushJson(200, self::envelope(self::nameData(['coverageStatus' => null, 'publicationId' => null])));

        $this->assertTrue($sanction->screenName(new ScreenNameRequest('Can Örnek'))->isClear());
        $partial = $sanction->screenName(new ScreenNameRequest('Can Örnek'));
        $this->assertFalse($partial->isClear());
        $this->assertSame(['vessel'], $partial->uncoveredRiskDomains());
        $this->assertFalse($sanction->screenName(new ScreenNameRequest('Can Örnek'))->isClear());
        $replay = $sanction->screenName(new ScreenNameRequest('Can Örnek'));
        $this->assertFalse($replay->isClear(), 'a replay without coverage is not clear');
        $this->assertNull($replay->publicationId());
    }

    public function testAScreeningThatCannotRunThrows(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(503, ['status' => 'failure', 'code' => 'sanction.screen_name.failed', 'message' => 'No list publication.', 'statusCode' => 503]);

        try {
            $sanction->screenName(new ScreenNameRequest('Can Örnek'));
            $this->fail('a failed screening must throw');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('sanction.screen_name.failed', $e->getErrorCode());
        }
    }

    public function testA2xxFailureEnvelopeIsRefused(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, ['status' => 'failure', 'code' => 'sanction.wallet.failed', 'data' => ['hits' => [], 'publicationHits' => []]]);

        $this->expectException(ApiException::class);
        $sanction->screenWallet('0x0000000000000000000000000000000000000001');
    }

    public function testRequestChecksBeforeSending(): void
    {
        [$sanction, $http] = $this->make();
        foreach ([
            static fn () => (new ScreenNameRequest('Can Örnek'))->withRiskDomains(['pep', 'crypto']),
            static fn () => (new ScreenNameRequest('Can Örnek'))->withRiskDomains([]),
            static fn () => $sanction->screenWallet('0x01', 'ethereum', ['mode' => 'full']),
            static fn () => $sanction->screenPublicProcurementBan(['onlyActive' => true]),
            static fn () => $sanction->screenTransaction(['amount' => 100, 'parties' => []]),
            static fn () => $sanction->screenTransaction(['parties' => [['name' => 'Can Örnek', 'iban' => 'x']]]),
            static fn () => $sanction->screenAccount('TR000000000000000000000000', 'iban', ['merchantId' => 'm_1']),
            static fn () => $sanction->listMonitoringAlerts(['severity' => 'high']),
            static fn () => $sanction->getEntityGraph(' '),
        ] as $index => $call) {
            try {
                $call();
                $this->fail("case {$index} must be refused before sending");
            } catch (ConfigurationException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $http->callCount(), 'nothing reached the gateway');
    }

    public function testWalletKeepsPublicationHitsApartFromYourListHits(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, self::envelope([
            'address' => '0xabc', 'chain' => 'ethereum', 'isSanctioned' => true, 'riskScore' => 100, 'riskLevel' => 'critical',
            'publicationId' => 'pub_1', 'publicationRevision' => 34, 'coverageStatus' => 'COMPLETE', 'evidenceRef' => 'ev_w',
            'publicationHits' => [[
                'hitId' => 'wp1', 'entityName' => 'Örnek Holding', 'riskCategory' => 'sanctions', 'score' => 100,
                'listedAddress' => '0xABC', 'listedChains' => ['ETH'], 'caseRule' => 'EVM_HEX',
                'lists' => [['datasetCode' => 'OFAC']],
            ]],
            'hits' => [['hitId' => 'wh1', 'riskCategory' => 'mixer', 'score' => 0.4]],
        ], ['code' => 'sanction.wallet.completed']));

        $result = $sanction->screenWallet('0xabc', 'ethereum', ['mode' => WalletScreenMode::WALLET_RISK]);

        $this->assertTrue($result->isSanctioned());
        $this->assertTrue($result->isHit());
        $this->assertFalse($result->isClear());
        $this->assertCount(1, $result->publicationHits());
        $this->assertSame('EVM_HEX', $result->publicationHits()[0]->caseRule());
        $this->assertSame(['ETH'], $result->publicationHits()[0]->listedChains());
        $this->assertSame('OFAC', $result->publicationHits()[0]->lists()[0]->datasetCode());
        $this->assertSame('wh1', $result->hits()[0]['hitId']);
        $this->assertSame(100.0, $result->riskScore());

        $body = json_decode((string) $http->lastCall()['body'], true);
        $this->assertSame(['walletAddress' => '0xabc', 'chain' => 'ethereum', 'mode' => 'wallet_risk'], $body);
    }

    public function testProcurementBanIsNotClearWhileKikIsUncovered(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, self::envelope([
            'matched' => false, 'riskScore' => 0, 'decision' => 'allow', 'hits' => [],
            'publicationId' => 'pub_1', 'publicationRevision' => 34, 'coverageStatus' => 'PARTIAL', 'evidenceRef' => 'ev_p',
            'screenedRiskDomains' => ['debarment'],
            'searchedLists' => [['datasetCode' => 'WB_DEBARRED', 'displayName' => 'World Bank', 'datasetVersionId' => 'v1']],
            'uncoveredSources' => [['datasetCode' => 'TR_PUBLIC_PROCUREMENT_BAN', 'displayName' => 'KİK', 'reason' => 'NOT_PUBLISHED']],
        ], ['code' => 'sanction.public_procurement.completed']));

        $result = $sanction->screenPublicProcurementBan(['vkn' => '0000000000', 'onlyActive' => true]);

        $this->assertFalse($result->isHit());
        $this->assertFalse($result->isClear());
        $this->assertSame(CoverageStatus::PARTIAL, $result->coverageStatus());
        $this->assertSame('WB_DEBARRED', $result->searchedLists()[0]['datasetCode']);
        $this->assertSame('TR_PUBLIC_PROCUREMENT_BAN', $result->uncoveredSources()[0]['datasetCode']);
        $this->assertStringEndsWith('/api/v1/sanction/intelligence/public-procurement-ban', $http->lastCall()['url']);
    }

    public function testTransactionNeedsCompleteCoverageAndAccountDoesNot(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, self::envelope(['decision' => 'allow', 'riskScore' => 0, 'caseRequired' => false, 'coverageStatus' => 'PARTIAL', 'resultsJson' => '{"parties":[]}']));
        $http->pushJson(200, self::envelope(['decision' => 'allow', 'riskScore' => 0, 'caseRequired' => false, 'coverageStatus' => 'COMPLETE']));
        $http->pushJson(200, self::envelope(['isSanctioned' => false, 'isBlacklisted' => false, 'riskScore' => 0, 'hits' => []]));

        $partial = $sanction->screenTransaction(['transactionType' => 'transfer', 'amount' => 100, 'currency' => 'TRY', 'parties' => [['role' => 'payee', 'name' => 'Can Örnek']]]);
        $this->assertFalse($partial->isClear());
        $this->assertSame(['parties' => []], $partial->results());
        $this->assertTrue($sanction->screenTransaction(['accounts' => [['accountType' => 'iban', 'value' => 'TR000000000000000000000000']]])->isClear());
        $this->assertTrue($sanction->screenAccount('TR000000000000000000000000', 'iban')->isClear());
        $this->assertStringEndsWith('/api/v1/sanction/screening/account', $http->lastCall()['url']);
    }

    public function testListsReadCursorAndPageInfo(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, self::envelope(['items' => [['runId' => 'r1']], 'nextCursor' => 'c2', 'runsToday' => 2]));
        $http->pushJson(200, self::envelope(['items' => [['runId' => 'r2']], 'nextCursor' => '']));

        $runs = [];
        foreach ($sanction->iterateScreeningHistory(['limit' => 1]) as $row) {
            $runs[] = $row['runId'];
        }
        $this->assertSame(['r1', 'r2'], $runs);
        $this->assertStringEndsWith('/api/v1/sanction/screening/history?limit=1&cursor=c2', $http->lastCall()['url']);

        $http->pushJson(200, self::envelope(['items' => [['type' => 'person', 'label' => 'Can Örnek']], 'totalEntries' => 7], [
            'pageInfo' => ['hasMore' => true, 'nextCursor' => 'b2', 'totalCount' => 7],
        ]));
        $page = $sanction->listBlacklistEntries(['limit' => 1]);
        $this->assertSame('b2', $page->nextCursor());
        $this->assertTrue($page->hasMore());
        $this->assertSame(7, $page->totalCount());

        $http->pushJson(200, self::envelope(['items' => [['alertId' => 'a1']], 'nextCursor' => '']));
        $this->assertSame([['alertId' => 'a1']], $sanction->monitoringAlerts(['status' => 'open', 'limit' => 25]));
        $this->assertStringEndsWith('/api/v1/sanction/monitoring/alerts?status=open&limit=25', $http->lastCall()['url']);
    }

    public function testMonitoringAndIntelligenceRoutes(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, self::envelope(['monitoredEntityId' => 'me_1']));
        $this->assertSame('me_1', $sanction->registerMonitoring('42', ['name' => 'Can Örnek', 'country' => 'TR'], SubjectType::PERSON, ['riskDomainScope' => ['sanctions']]));
        $body = json_decode((string) $http->lastCall()['body'], true);
        $this->assertSame('42', $body['externalCustomerId']);
        $this->assertStringContainsString('Can Örnek', (string) $body['profileJson']);
        $this->assertSame(['sanctions'], $body['riskDomainScope']);

        $http->pushJson(200, self::envelope(['items' => [], 'nextCursor' => '']));
        $sanction->listMonitoredEntities(['activeOnly' => false, 'includeTotal' => true]);
        $this->assertStringEndsWith('/api/v1/sanction/monitoring/entities?activeOnly=false&includeTotal=true', $http->lastCall()['url']);

        $http->pushJson(200, self::envelope(['entityId' => 'e/1', 'profile' => []]));
        $this->assertSame('e/1', $sanction->getEntityProfile('e/1', 'scr_1')['entityId']);
        $this->assertStringEndsWith('/api/v1/sanction/intelligence/entity-profiles/e%2F1?screeningRequestId=scr_1', $http->lastCall()['url']);

        $http->pushJson(200, self::envelope(['paths' => []]));
        $sanction->getIndirectExposure('e1', 3, ['e1', 'e2']);
        $this->assertStringEndsWith('/api/v1/sanction/intelligence/indirect-exposure/e1?maxDepth=3&entityIds=e1%2Ce2', $http->lastCall()['url']);

        $http->pushJson(200, self::envelope(['items' => []]));
        $sanction->searchEntities('Örnek', 5);
        $this->assertSame('GET', $http->lastCall()['method']);
        $this->assertNull($http->lastCall()['body']);
    }

    public function testScreenNameRequiresName(): void
    {
        $this->expectException(ConfigurationException::class);
        new ScreenNameRequest('   ');
    }

    public function testSubmitHitDecisionPostsDisposition(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'decisionId' => 'd1', 'finalDisposition' => 'false_positive']);

        $res = $sanction->submitHitDecision(['hitId' => 'h1', 'analystId' => 'a1', 'analystDisposition' => 'false_positive']);

        $this->assertSame('d1', $res['decisionId']);
        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/sanction/analyst/hit-decisions', $call['url']);
    }

    public function testAssignCaseEncodesPath(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'caseId' => 'c1', 'ownerUserId' => 'a1', 'caseStatus' => 'assigned']);

        $sanction->assignCase('c1', 'a1');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/sanction/analyst/cases/c1/assign', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('a1', $body['analystId']);
    }

    public function testTheRetiredProcurementSyncIsGone(): void
    {
        $this->assertFalse(method_exists(SanctionClient::class, 'syncProcurementBans'));
    }

    /** The route table's single source is the Node SDK's SANCTION_ROUTES. */
    public function testTheRouteTableMatchesTheNodeSdk(): void
    {
        $source = dirname(__DIR__, 3) . '/node/src/sanction/sanction-values.js';
        if (!is_dir(dirname(__DIR__, 3) . '/node')) {
            $this->markTestSkipped('Parity with the Node SDK is checked in the Lunixi monorepo only.');
        }
        $this->assertFileExists($source);
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $json = shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg(
            'const v = require(process.argv[1]); process.stdout.write(JSON.stringify({ routes: v.SANCTION_ROUTES, nested: v.SANCTION_NESTED_FIELDS, values: v.SANCTION_VALUE_SETS }))'
        ) . ' ' . escapeshellarg($source));
        $node = json_decode((string) $json, true);
        $this->assertSame($node['routes'], SanctionRoutes::ROUTES);
        $this->assertSame($node['nested'], SanctionRoutes::NESTED_FIELDS);
        $this->assertSame($node['values'], SanctionRoutes::VALUE_SETS);
        $this->assertSame(RiskDomain::ALL, SanctionRoutes::VALUE_SETS['screenName.riskDomains']);
        $this->assertSame(WalletScreenMode::ALL, SanctionRoutes::VALUE_SETS['screenWallet.mode']);
    }
}
