<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Fraud;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\CanonicalRequest;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Fraud\FraudClient;
use Lunixi\Sdk\Fraud\FraudEventsClient;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class FraudEventsClientTest extends TestCase
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

    /** @return array<string,mixed> */
    private function event(string $eventId = 'evt_1'): array
    {
        return [
            'eventId' => $eventId,
            'eventType' => 'transfer_completed',
            'subjectType' => 'transaction',
            'occurredAt' => '2026-10-05T09:12:40.000Z',
            'counterparty' => 'TR330006100519786457841326',
            'amountMinor' => 250000,
            'currency' => 'TRY',
        ];
    }

    /**
     * 🔴 TAM YOL ÖLÇÜLÜR. `/api/v1` öneki eksik yazılsaydı gateway tüm fraud
     *    yüzeyine 404 verirdi (karar istemcisinde 0.11.0 öncesinde ölçüldü), ve
     *    yalnız sonu eşleştiren bir iddia bunu göremezdi.
     */
    public function testRecordSignsEveryRequestAndHitsThePrefixedPath(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            'eventId' => 'evt_1', 'id' => 'row_1', 'deduplicated' => false, 'matchedFlowCount' => 2,
        ]]);

        $result = $fraud->events->record($this->event());

        $this->assertSame('evt_1', $result->eventId());
        $this->assertSame('row_1', $result->rowId());
        $this->assertFalse($result->deduplicated());
        $this->assertSame(2, $result->matchedFlowCount());
        $this->assertFalse($result->failed());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/fraud/events/transactions', $call['url']);

        // Olay alımı Bearer'la DEĞİL istek başına imzayla korunur: imza başlıkları
        // ZORUNLU. Karar yüzeyi (`FraudClientTest`) bunun tersini kilitler.
        $this->assertArrayHasKey(CanonicalRequest::HEADER_KEY_ID, $call['headers']);
        $this->assertArrayHasKey(CanonicalRequest::HEADER_NONCE, $call['headers']);
        $this->assertArrayHasKey(CanonicalRequest::HEADER_DATE, $call['headers']);
        $this->assertArrayHasKey(CanonicalRequest::HEADER_SIGNATURE, $call['headers']);
        $this->assertArrayHasKey(CanonicalRequest::HEADER_DIGEST, $call['headers']);
    }

    /**
     * 🔴 ÖZET SIKIŞTIRILMIŞ GÖVDE ÜZERİNDEN. Gateway aldığı gövdeyi ayrıştırıp
     *    yeniden serileştirerek doğruluyor; girintili gövde imzalanırsa HER istek
     *    401 INVALID_DIGEST döner (Postman koleksiyonunda ölçülmüş defekt).
     */
    public function testDigestIsComputedOverTheExactBytesSent(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['eventId' => 'evt_1', 'deduplicated' => false]]);

        $fraud->events->record($this->event());
        $call = $http->lastCall();

        $this->assertNotNull($call['body']);
        $this->assertStringNotContainsString("\n", (string) $call['body'], 'body must be compact JSON');
        $this->assertSame(
            CanonicalRequest::digestForBody((string) $call['body']),
            $call['headers'][CanonicalRequest::HEADER_DIGEST]
        );
    }

    public function testDeduplicatedResponseHasNoRowId(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['eventId' => 'evt_1', 'deduplicated' => true]]);

        $result = $fraud->events->record($this->event());

        $this->assertTrue($result->deduplicated());
        $this->assertNull($result->rowId());
    }

    public function testBatchIsSentAsAJsonArray(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            'total' => 2, 'inserted' => 2, 'deduplicated' => 0, 'failed' => 0,
            'results' => [
                ['eventId' => 'evt_1', 'id' => 'row_1', 'deduplicated' => false],
                ['eventId' => 'evt_2', 'id' => 'row_2', 'deduplicated' => false],
            ],
        ]]);

        $result = $fraud->events->recordBatch([$this->event('evt_1'), $this->event('evt_2')]);

        $this->assertSame(2, $result->total());
        $this->assertSame(2, $result->inserted());
        $this->assertSame(0, $result->failed());
        $this->assertCount(2, $result->results());
        $this->assertSame([], $result->failures());

        $call = $http->lastCall();
        $this->assertSame('https://gw.example.com/api/v1/fraud/events/transactions/batch', $call['url']);
        $decoded = json_decode((string) $call['body'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(0, $decoded, 'batch body must be a JSON array, not an object');
        $this->assertSame('evt_2', $decoded[1]['eventId']);
    }

    /**
     * 🔴 PARTİ 200 DÖNERKEN OLAY DÜŞEBİLİR. Yalnız HTTP durumuna bakan bir
     *    entegrasyon olayları sessizce kaybeder; `failures()` o olayları ayırır.
     */
    public function testPartialBatchFailureIsVisibleOnATwoHundredResponse(): void
    {
        [$fraud, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            'total' => 2, 'inserted' => 1, 'deduplicated' => 0, 'failed' => 1,
            'results' => [
                ['eventId' => 'evt_1', 'id' => 'row_1', 'deduplicated' => false],
                ['eventId' => 'evt_2', 'deduplicated' => false, 'error' => 'Event is missing required field(s): occurredAt.'],
            ],
        ]]);

        $result = $fraud->events->recordBatch([$this->event('evt_1'), $this->event('evt_2')]);

        $this->assertSame(1, $result->failed());
        $failures = $result->failures();
        $this->assertCount(1, $failures);
        $this->assertSame('evt_2', $failures[0]->eventId());
        $this->assertStringContainsString('occurredAt', (string) $failures[0]->error());
    }

    public function testEmptyBatchIsRejectedBeforeTheNetwork(): void
    {
        [$fraud, $http] = $this->make();

        $this->expectException(ConfigurationException::class);
        try {
            $fraud->events->recordBatch([]);
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }

    /**
     * Gateway partinin TAMAMINI reddediyor; sınır yerelde de uygulanır ki çağıran
     * 500 olayı tek bir aşırı büyük çağrıya kaybetmesin.
     */
    public function testOversizedBatchIsRejectedBeforeTheNetwork(): void
    {
        [$fraud, $http] = $this->make();
        $events = [];
        for ($i = 0; $i <= FraudEventsClient::BATCH_LIMIT; $i++) {
            $events[] = $this->event('evt_' . $i);
        }

        $this->expectException(ConfigurationException::class);
        try {
            $fraud->events->recordBatch($events);
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testNonListBatchIsRejected(): void
    {
        [$fraud, $http] = $this->make();

        $this->expectException(ConfigurationException::class);
        try {
            /** @phpstan-ignore-next-line intentionally wrong shape */
            $fraud->events->recordBatch(['first' => $this->event()]);
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }
}
