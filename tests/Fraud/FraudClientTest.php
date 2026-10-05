<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Fraud;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Fraud\EvaluateRequest;
use Lunixi\Sdk\Fraud\FraudClient;
use Lunixi\Sdk\Fraud\FraudDecisionValue;
use Lunixi\Sdk\Fraud\FraudEvents;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class FraudClientTest extends TestCase
{
    /** @return array{0:FraudClient,1:FakeHttpClient} */
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

        return [new FraudClient(new ApiClient($config, $signer, $tokens, $http)), $http];
    }

    public function testEvaluateIsBearerOnlyAndMapsDecision(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            'decision' => 'force_3d', 'score' => 72.5, 'traceId' => 'tr_1',
            'reasonCodes' => ['high_amount_non_3d', 'new_customer'],
            'matchedRules' => ['rule_7'], 'tags' => ['watch'],
            'explainability' => ['summary' => 'Elevated risk; step-up advised.'],
        ]]);

        $req = (new EvaluateRequest())
            ->withSubjectType('card_payment')
            ->withProductContext('payment_form')
            ->withPayment(['amount' => 25000, 'currency' => 'TRY', 'is3D' => false])
            ->withCustomer(['email' => 'a@b.com', 'isNew' => true]);
        $decision = $fraud->evaluate($req);

        $this->assertSame(FraudDecisionValue::FORCE_3D, $decision->decision());
        $this->assertTrue($decision->isForce3D());
        $this->assertSame(72.5, $decision->score());
        $this->assertSame(['high_amount_non_3d', 'new_customer'], $decision->reasonCodes());
        $this->assertSame('Elevated risk; step-up advised.', $decision->explainabilitySummary());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        // 🔴 TAM YOL olculur. Eskiden `assertStringEndsWith('/fraud/decisions/evaluate')`
        //    yaziliydi ve `/api/v1` oneki EKSIKKEN de geciyordu — SDK'nin tamami
        //    404 donerken bu test yesildi. Onek testin kapsamina girmedigi icin
        //    hatayi kimse gormedi; artik tam yol kilitli.
        $this->assertStringEndsWith('/api/v1/fraud/decisions/evaluate', $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'fraud is bearer-only, not step-up');
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('card_payment', $body['subjectType']);
        $this->assertSame(25000, $body['payment']['amount']);
        $this->assertFalse($body['payment']['is3D']);
    }

    public function testLatestForPaymentQueriesByPaymentId(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            ['decision' => 'block', 'score' => 95, 'traceId' => 'tr_9', 'paymentId' => 'pi_1'],
        ]]);

        $decision = $fraud->latestForPayment('pi_1');

        $this->assertNotNull($decision);
        $this->assertTrue($decision->isBlock());
        $this->assertSame('pi_1', $decision->paymentId());

        $url = $http->lastCall()['url'];
        $this->assertStringContainsString('/api/v1/fraud/decision-logs', $url);
        $this->assertStringContainsString('paymentId=pi_1', $url);
        $this->assertStringContainsString('limit=1', $url);
    }

    public function testDecisionLogByTraceId(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['decision' => 'allow', 'score' => 5, 'traceId' => 'tr_x']]);

        $decision = $fraud->decisionLog('tr_x');

        $this->assertTrue($decision->isAllow());
        $this->assertStringEndsWith('/api/v1/fraud/decision-logs/tr_x', $http->lastCall()['url']);
    }

    public function testDecisionLogsSendsCursorAndReadsSiblingPageInfo(): void
    {
        [$fraud, $http] = $this->make();
        // `pageInfo` is a SIBLING of `data` in the gateway envelope, not nested in it.
        $http->pushJson(200, [
            'success' => true,
            'data' => [['traceId' => 'tr_1', 'decision' => 'allow']],
            'pageInfo' => ['hasMore' => true, 'nextCursor' => 'cur_abc', 'totalCount' => null],
        ]);

        $list = $fraud->decisionLogs(['limit' => 1, 'cursor' => 'cur_prev']);

        $call = $http->lastCall();
        $this->assertStringContainsString('cursor=cur_prev', $call['url']);
        $this->assertSame(1, $list->count());
        $this->assertTrue($list->hasMore());
        $this->assertSame('cur_abc', $list->nextCursor());
        $this->assertNull($list->totalCount());
    }

    public function testDecisionLogsLastPageHasNoCursor(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, [
            'success' => true,
            'data' => [['traceId' => 'tr_9', 'decision' => 'allow']],
            'pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => 12],
        ]);

        $list = $fraud->decisionLogs();

        $this->assertFalse($list->hasMore());
        $this->assertNull($list->nextCursor());
        $this->assertSame(12, $list->totalCount());
    }

    public function testFraudEventsAreThePlatformCatalogueKeys(): void
    {
        // Locked against organization-service `webhook-event-catalog.ts` (FRAUD_SERVICE)
        // and fraud-service `FRAUD_PLATFORM_EVENT_TYPES`. Internal Kafka topics
        // (`*.v1`) are NOT webhook event types.
        $this->assertSame([
            'fraud.flow.async_completed',
            'fraud.flow.action_triggered',
            'fraud.quota.threshold_reached',
            'fraud.alert.triggered',
            'fraud.service.degraded',
            'fraud.service.recovered',
            'fraud.report.ready',
        ], FraudEvents::all());

        foreach (FraudEvents::all() as $key) {
            $this->assertStringStartsWith('fraud.', $key);
            $this->assertStringEndsNotWith('.v1', $key);
        }
    }
}
