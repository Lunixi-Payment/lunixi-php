<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Identity;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Identity\AnalyzeRequest;
use Lunixi\Sdk\Identity\IdentityClient;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class IdentityClientTest extends TestCase
{
    /** @return array{0:IdentityClient,1:FakeHttpClient} */
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

        return [new IdentityClient(new ApiClient($config, $signer, $tokens, $http)), $http];
    }

    public function testAccountLinksDetectsMultiAccount(): void
    {
        [$identity, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            'totalLinkedSessions' => 5,
            'linkedAccounts' => ['hashA', 'hashB', 'hashC'],
            'linkedTrials' => ['trial1'],
            'sessions' => [
                ['traceId' => 't1', 'accountKeyHash' => 'hashB', 'decision' => 'allow', 'linkReasons' => ['same_device_cluster', 'same_ip_asn']],
                ['traceId' => 't2', 'accountKeyHash' => 'hashC', 'decision' => 'review', 'linkReasons' => ['same_fingerprint']],
            ],
        ]]);

        $links = $identity->accountLinks('trace_1');

        $this->assertSame(5, $links->totalLinkedSessions());
        $this->assertSame(3, $links->linkedAccountCount());  // OTHER accounts on the device
        $this->assertSame(4, $links->accountCount());        // + the current one
        $this->assertTrue($links->isShared());               // ≥2 distinct accounts on the device
        $this->assertTrue($links->isShared(4));              // exactly meets a stricter threshold
        $this->assertFalse($links->isShared(5));             // below a stricter threshold
        $this->assertEqualsCanonicalizing(
            ['same_device_cluster', 'same_ip_asn', 'same_fingerprint'],
            $links->linkReasons()
        );

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertStringEndsWith('/api/v1/identity/live-sessions/trace_1/account-links', $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'identity is bearer-only');
    }

    public function testAnalyzeSyncReturnsTraceAndIdentitySignals(): void
    {
        [$identity, $http] = $this->make();
        // Real FraudLiveSessionAnalyzeResponse shape: traceId + risk signals +
        // identitySnapshot (no allow/review/block verdict — that's fraud-service).
        $http->pushJson(200, ['success' => true, 'data' => [
            'traceId' => 'tr_9',
            'analysisStatus' => 'completed',
            'riskFlags' => ['same_device_cluster'],
            'reasonCodes' => ['SAME_DEVICE'],
            'sameActorProbability' => 0.82,
            'identitySnapshot' => ['snapshotId' => 's1', 'decisionGrade' => true],
        ]]);

        $req = (new AnalyzeRequest())
            ->withSubject('user', '42')
            ->withDevice(['deviceId' => 'fp_abc', 'ipAddress' => '1.2.3.4'])
            ->withAccount(['accountId' => '42']);
        $decision = $identity->analyzeSync($req);

        $this->assertSame('tr_9', $decision->traceId());
        $this->assertSame('completed', $decision->analysisStatus());
        $this->assertTrue($decision->hasRiskFlags());
        $this->assertSame(['same_device_cluster'], $decision->riskFlags());
        $this->assertSame(['SAME_DEVICE'], $decision->reasonCodes());
        $this->assertSame(0.82, $decision->sameActorProbability());
        $this->assertTrue($decision->decisionGrade());            // from identitySnapshot
        $this->assertSame('s1', $decision->snapshot()['snapshotId']);

        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/identity/live-sessions/analyze-sync', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('42', $body['subjectId']);
        $this->assertSame('fp_abc', $body['device']['deviceId']);
        $this->assertSame('42', $body['account']['accountId']);
    }

    public function testListSessionsFiltersByDevice(): void
    {
        [$identity, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [['traceId' => 's1']]]);

        $sessions = $identity->listSessions(['deviceId' => 'fp_abc', 'limit' => 10]);
        $this->assertCount(1, $sessions);
        $this->assertStringContainsString('deviceId=fp_abc', $http->lastCall()['url']);
    }

    public function testTimelineAndRelatedSessionRoutes(): void
    {
        [$identity, $http] = $this->make();

        $http->pushJson(200, ['events' => []]);
        $identity->timeline('tr_1');
        $this->assertStringEndsWith('/api/v1/identity/live-sessions/tr_1/timeline', $http->lastCall()['url']);

        $http->pushJson(200, ['sessions' => []]);
        $identity->relatedSessions('tr_1', 5);
        $url = $http->lastCall()['url'];
        $this->assertStringContainsString('/api/v1/identity/live-sessions/tr_1/related-sessions', $url);
        $this->assertStringContainsString('limit=5', $url);
    }
}
