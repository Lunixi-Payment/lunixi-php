<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Sanction;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Sanction\SanctionClient;
use Lunixi\Sdk\Sanction\ScreenMode;
use Lunixi\Sdk\Sanction\ScreenNameRequest;
use Lunixi\Sdk\Sanction\SubjectType;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

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

    public function testScreenNameParsesRootMatches(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, [
            'success' => true, 'requestId' => 'req_1', 'latencyMs' => 42,
            'matches' => [
                ['hitId' => 'h1', 'matchedName' => 'John Smith', 'listCode' => 'OFAC', 'score' => 0.92, 'hitType' => 'probable'],
            ],
        ]);

        $req = (new ScreenNameRequest('John Smith'))
            ->withCountry('TR')
            ->withSubjectType(SubjectType::PERSON)
            ->withMode(ScreenMode::SANCTIONS_PEP)
            ->withCustomerReference('42');
        $result = $sanction->screenName($req);

        $this->assertTrue($result->hasMatches());
        $this->assertFalse($result->isClear());
        $this->assertSame('req_1', $result->requestId());
        $this->assertSame('OFAC', $result->matches()[0]->listCode());
        $this->assertSame(0.92, $result->topScore());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/sanction/screening/name', $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'sanction is bearer-only');
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('John Smith', $body['name']);
        $this->assertSame('TR', $body['country']);
        $this->assertSame('sanctions_pep', $body['mode']);
        $this->assertSame('42', $body['customerReference']);
    }

    public function testScreenNameClearWhenNoMatches(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'requestId' => 'req_2', 'matches' => []]);

        $result = $sanction->screenName(new ScreenNameRequest('Jane Doe'));
        $this->assertTrue($result->isClear());
        $this->assertSame(0.0, $result->topScore());
    }

    public function testRegisterMonitoringReturnsEntityId(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'monitoredEntityId' => 'me_1']);

        $id = $sanction->registerMonitoring('42', ['name' => 'John Smith', 'country' => 'TR']);

        $this->assertSame('me_1', $id);
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/sanction/monitoring/register', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('42', $body['externalCustomerId']);
        $this->assertStringContainsString('John Smith', (string) $body['profileJson']);
    }

    public function testAddBlacklistSendsTypeAndName(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'entityId' => 'bl_1']);

        $id = $sanction->addBlacklist('person', 'Bad Actor', ['reason' => 'fraud']);

        $this->assertSame('bl_1', $id);
        $body = json_decode((string) $http->lastCall()['body'], true);
        $this->assertSame('person', $body['type']);
        $this->assertSame('Bad Actor', $body['name']);
        $this->assertSame('fraud', $body['reason']);
    }

    public function testScreenNameRequiresName(): void
    {
        $this->expectException(ConfigurationException::class);
        new ScreenNameRequest('   ');
    }

    public function testScreenWalletNormalisesOutcome(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, [
            'success' => true, 'requestId' => 'rq_w', 'address' => '0xabc', 'chain' => 'ethereum',
            'isSanctioned' => true, 'riskScore' => 0.81, 'riskLevel' => 'high',
            'hits' => [['hitId' => 'wh1', 'riskCategory' => 'sanctioned', 'score' => 0.81]],
        ]);

        $outcome = $sanction->screenWallet('0xabc', 'ethereum', ['mode' => 'full']);

        $this->assertTrue($outcome->isHit());
        $this->assertSame('high', $outcome->riskLevel());
        $this->assertSame(0.81, $outcome->riskScore());
        $this->assertCount(1, $outcome->hits());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/sanction/screening/wallet', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('0xabc', $body['walletAddress']);
        $this->assertSame('ethereum', $body['chain']);
        $this->assertSame('full', $body['mode']);
    }

    public function testScreenTransactionClearDecision(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'decision' => 'allow', 'riskScore' => 0.1, 'caseRequired' => false, 'hits' => []]);

        $outcome = $sanction->screenTransaction(['transactionType' => 'transfer', 'amount' => 100, 'currency' => 'TRY']);

        $this->assertTrue($outcome->isClear());
        $this->assertSame('allow', $outcome->decision());
        $this->assertStringEndsWith('/api/v1/sanction/screening/transaction', $http->lastCall()['url']);
    }

    public function testScreeningHistoryExtractsItems(): void
    {
        [$sanction, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'items' => [['runId' => 'r1'], ['runId' => 'r2']]]);

        $rows = $sanction->screeningHistory(['limit' => 10]);

        $this->assertCount(2, $rows);
        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertStringContainsString('/api/v1/sanction/screening/history', $call['url']);
        $this->assertStringContainsString('limit=10', $call['url']);
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
}
