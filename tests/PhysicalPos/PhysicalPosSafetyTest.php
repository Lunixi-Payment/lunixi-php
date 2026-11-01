<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\PhysicalPos;

use DateTimeImmutable;
use DateTimeZone;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Exception\PhysicalPosCardDataRejectedException;
use Lunixi\Sdk\Exception\PhysicalPosOutcomeUnknownException;
use Lunixi\Sdk\Exception\PhysicalPosRateLimitException;
use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Sdk\Http\HttpResponse;
use Lunixi\Sdk\PhysicalPos\PhysicalPosClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosManagementClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosPayment;
use Lunixi\Sdk\PhysicalPos\PhysicalPosWebhookEvent;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Money-safety behaviour of the physical POS clients (D-02 review): required
 * payment keys (Y-1), unreadable 2xx answers (Y-2), uncertainty kept across the
 * attempt chain (O-1), keys sent as given (O-2), webhooks only after signature
 * verification (O-3), card numbers in text values (O-4) and the smaller fixes.
 * Ids and values are synthetic.
 */
final class PhysicalPosSafetyTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';
    private const PAYMENT_ID = '0192f3a1-0000-7000-8000-000000000001';
    private const KEY = 'pos-safety-key-0001';
    private const SECRET = 'whsec_test_safety';

    /** @return array{0:ApiClient,1:FakeHttpClient} */
    private static function api(int $maxRetries): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration([
            'baseUrl' => self::BASE_URL, 'keyId' => 'mk_live_sk_kid_1', 'privateKey' => $keys['privateKey'], 'maxRetries' => $maxRetries,
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $store->set($config->tokenCacheKey(), 'at_test_seeded', 3600);

        return [new ApiClient($config, $signer, new TokenManager($config, $signer, $http, $store), $http), $http];
    }

    /** @return array{0:PhysicalPosClient,1:FakeHttpClient} */
    private static function pos(int $maxRetries = 0): array
    {
        [$api, $http] = self::api($maxRetries);

        return [new PhysicalPosClient($api), $http];
    }

    /** @return array{0:PhysicalPosManagementClient,1:FakeHttpClient} */
    private static function management(): array
    {
        [$api, $http] = self::api(0);

        return [new PhysicalPosManagementClient($api), $http];
    }

    /** @return array<string,mixed> */
    private static function sale(array $overrides = []): array
    {
        return array_merge(
            ['operation' => 'SALE', 'target' => ['deviceId' => 'dev-1'], 'amountMinor' => "4500", 'currency' => 'TRY'],
            $overrides
        );
    }

    /** @return array<string,mixed> */
    private static function accepted(bool $replayed = false): array
    {
        return [
            'status' => 'success', 'statusCode' => $replayed ? 200 : 202,
            'data' => ['payment' => ['paymentId' => self::PAYMENT_ID, 'paymentStatus' => 'NOT_STARTED', 'amountMinor' => "4500"], 'replayed' => $replayed],
        ];
    }

    /** @return array<string,mixed> */
    private static function failure(int $status, string $code, array $details = []): array
    {
        return ['status' => 'failure', 'code' => $code, 'message' => 'Rejected.', 'statusCode' => $status, 'details' => $details];
    }

    /** @param array{body:?string} $call @return mixed */
    private static function bodyOf(array $call)
    {
        return $call['body'] === null ? null : json_decode($call['body'], true);
    }

    // ── Y-1 ─────────────────────────────────────────────────────────────────

    public function testPaymentWritesNeedAStoredKeyAndCancelMakesItsOwn(): void
    {
        [$client, $http] = self::pos();
        foreach ([
            static fn () => $client->createPayment(self::sale()),
            static fn () => $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY']),
        ] as $call) {
            try {
                $call();
                $this->fail('expected ConfigurationException');
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString('same key', strtolower($error->getMessage()));
            }
        }
        $this->assertSame(0, $http->callCount());

        $http->pushJson(200, self::accepted(true));
        $result = $client->cancelPayment(self::PAYMENT_ID);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result->idempotencyKey());
        $this->assertSame($result->idempotencyKey(), $http->lastCall()['headers']['Idempotency-Key']);
    }

    // ── Y-2 ─────────────────────────────────────────────────────────────────

    /** @return array<string,array{0:mixed}> */
    public static function unreadableAnswers(): array
    {
        return [
            'non-JSON 2xx' => [new HttpResponse(202, [], '<html>OK</html>')],
            'empty 2xx' => [new HttpResponse(202, [], '')],
            '2xx without payment' => [new HttpResponse(202, [], (string) json_encode(['status' => 'success', 'statusCode' => 202, 'data' => ['replayed' => false]]))],
            '2xx with an empty payment' => [new HttpResponse(200, [], (string) json_encode(['status' => 'success', 'data' => ['payment' => [], 'replayed' => true]]))],
            'body read timeout' => [new ApiException('HTTP transport error (28): Operation timed out after reading the headers')],
            'connection dropped (custom transport)' => [new \RuntimeException('connection reset by peer')],
        ];
    }

    /** @param mixed $answer */
    #[DataProvider('unreadableAnswers')]
    public function testAnUnreadableAnswerIsAnUnknownOutcomeWithTheKey($answer): void
    {
        foreach (['createPayment', 'createReversal', 'cancelPayment'] as $method) {
            [$client, $http] = self::pos();
            $http->push($answer);
            try {
                if ($method === 'createPayment') {
                    $client->createPayment(self::sale(), self::KEY);
                } elseif ($method === 'createReversal') {
                    $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY'], self::KEY);
                } else {
                    $client->cancelPayment(self::PAYMENT_ID, self::KEY);
                }
                $this->fail("{$method}: expected PhysicalPosOutcomeUnknownException, never a made-up 202");
            } catch (PhysicalPosOutcomeUnknownException $error) {
                $this->assertSame(self::KEY, $error->getIdempotencyKey(), $method);
                $this->assertNotNull($error->getPrevious(), $method);
            }
        }
    }

    public function testAnUncertainAttemptIsRepeatedWithTheSameKeyAndBody(): void
    {
        [$client, $http] = self::pos(1);
        $http->push(new HttpResponse(202, [], 'not json'));
        $http->pushJson(200, self::accepted(true));

        $result = $client->createPayment(self::sale(), self::KEY);

        $this->assertSame(2, $http->callCount());
        $this->assertSame($http->calls[0]['body'], $http->calls[1]['body']);
        $this->assertSame(self::KEY, $http->calls[1]['headers']['Idempotency-Key']);
        $this->assertTrue($result->replayed());
        $this->assertSame(200, $result->statusCode());
    }

    // ── O-1, D-1 ────────────────────────────────────────────────────────────

    /** @return array<string,array{0:\Closure(FakeHttpClient):void,1:int}> */
    public static function uncertainThenDefinite(): array
    {
        return [
            'timeout then 429' => [static function (FakeHttpClient $http): void {
                $http->push(new ApiException('HTTP transport error (28): timeout'));
                $http->pushJson(429, self::failure(429, 'RATE_LIMITED', ['retryAfterSeconds' => 30]), ['retry-after' => '30']);
            }, 429],
            'untyped 503 then 429' => [static function (FakeHttpClient $http): void {
                $http->pushJson(503, self::failure(503, 'SERVICE_UNAVAILABLE'));
                $http->pushJson(429, self::failure(429, 'RATE_LIMITED'));
            }, 429],
            '504 then idempotency mismatch' => [static function (FakeHttpClient $http): void {
                $http->pushJson(504, ['message' => 'Gateway Timeout']);
                $http->pushJson(409, self::failure(409, 'physical_pos.idempotency_mismatch'));
            }, 409],
            'non-JSON 2xx then device busy' => [static function (FakeHttpClient $http): void {
                $http->push(new HttpResponse(202, [], ''));
                $http->pushJson(409, self::failure(409, 'physical_pos.payment.device_busy'));
            }, 409],
        ];
    }

    #[DataProvider('uncertainThenDefinite')]
    public function testUncertaintyEarlierInTheChainWinsOverTheLastAnswer(\Closure $answers, int $lastStatus): void
    {
        [$client, $http] = self::pos(1);
        $answers($http);

        try {
            $client->createPayment(self::sale(), self::KEY);
            $this->fail('expected PhysicalPosOutcomeUnknownException');
        } catch (PhysicalPosRateLimitException $error) {
            $this->fail('a 429 after an uncertain attempt must not read as "not started"');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->assertSame(self::KEY, $error->getIdempotencyKey());
            $this->assertSame($lastStatus, $error->getStatusCode());
            $this->assertSame(2, $error->getAttempts());
            $this->assertInstanceOf(ApiException::class, $error->getPrevious());
        }
        $this->assertSame(2, $http->callCount());
    }

    public function testANotStartedAnswerIsDefiniteAndRetried(): void
    {
        [$client, $http] = self::pos(1);
        $http->pushJson(503, self::failure(503, 'physical_pos.presence.unavailable', ['retryable' => true]));
        $http->pushJson(409, self::failure(409, 'physical_pos.payment.device_offline', ['retryable' => true]));

        try {
            $client->createPayment(self::sale(), self::KEY);
            $this->fail('expected ApiException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->fail('nothing was written: the outcome is known');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }

        [$client, $http] = self::pos(0);
        $http->pushJson(503, self::failure(503, 'physical_pos.signing.unavailable'));
        try {
            $client->createPayment(self::sale(), self::KEY);
            $this->fail('expected ApiException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->fail('signing.unavailable writes nothing');
        } catch (ApiException $error) {
            $this->assertSame('physical_pos.signing.unavailable', $error->getErrorCode());
        }

        [$client, $http] = self::pos(1);
        $http->pushJson(503, self::failure(503, 'physical_pos.service.busy'));
        $http->pushJson(202, self::accepted());
        $this->assertSame(202, $client->createPayment(self::sale(), self::KEY)->statusCode());
    }

    public function testA429WithoutEarlierUncertaintyIsARateLimit(): void
    {
        [$client, $http] = self::pos(1);
        $http->pushJson(429, self::failure(429, 'RATE_LIMITED'), ['retry-after' => '12']);

        try {
            $client->createPayment(self::sale(), self::KEY);
            $this->fail('expected PhysicalPosRateLimitException');
        } catch (PhysicalPosRateLimitException $error) {
            $this->assertSame(12, $error->getRetryAfter(), 'Retry-After header (D-7)');
        }
        $this->assertSame(1, $http->callCount(), 'a 4xx is never repeated');
    }

    // ── O-2 ─────────────────────────────────────────────────────────────────

    public function testKeysAreSentAsGivenAndEmptyOrBlankKeysAreRefused(): void
    {
        [$client, $http] = self::pos();
        foreach (['', '   ', '          ', ' ' . self::KEY, self::KEY . ' '] as $key) {
            try {
                $client->createPayment(self::sale(), $key);
                $this->fail('expected ConfigurationException for ' . json_encode($key));
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString('Idempotency-Key', $error->getMessage());
            }
            try {
                $client->cancelPayment(self::PAYMENT_ID, $key);
                $this->fail('cancel: expected ConfigurationException for ' . json_encode($key));
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString('Idempotency-Key', $error->getMessage());
            }
        }
        [$management, $mhttp] = self::management();
        foreach (['', '          '] as $key) {
            try {
                $management->createStore(['code' => 'A', 'name' => 'B'], $key);
                $this->fail('management: expected ConfigurationException');
            } catch (ConfigurationException $error) {
                $this->assertSame(0, $mhttp->callCount());
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    // ── O-3 ─────────────────────────────────────────────────────────────────

    /** @return array{0:string,1:array<string,string>} */
    private static function delivery(array $envelope, string $secret = self::SECRET): array
    {
        $eventId = 'evt_safety_1';
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $body = (string) json_encode($envelope);

        return [$body, [
            'x-lunixi-event-id' => $eventId,
            'x-lunixi-event-type' => (string) ($envelope['type'] ?? ''),
            'x-lunixi-signature-timestamp' => $timestamp,
            'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $body, $secret),
        ]];
    }

    /** @return array<string,mixed> */
    private static function approvedEnvelope(): array
    {
        return [
            'id' => 'evt_safety_1', 'type' => 'physical_pos.payment.approved', 'environment' => 'LIVE',
            'data' => ['paymentId' => self::PAYMENT_ID, 'paymentStatus' => 'APPROVED', 'amountMinor' => '4500', 'historySeq' => 3],
        ];
    }

    public function testOnlyAVerifiedDeliveryIsRead(): void
    {
        [$body, $headers] = self::delivery(self::approvedEnvelope());
        $this->assertSame('APPROVED', PhysicalPosClient::parseWebhookEvent($body, $headers, self::SECRET)->data()['paymentStatus']);

        $forgeries = [
            'no signature headers' => [$body, []],
            'tampered body' => [str_replace('4500', '9500', $body), $headers],
            'other secret' => self::delivery(self::approvedEnvelope(), 'whsec_attacker'),
            'stale timestamp' => [$body, array_merge($headers, ['x-lunixi-signature-timestamp' => '2020-01-01T00:00:00Z'])],
        ];
        foreach ($forgeries as $name => [$forgedBody, $forgedHeaders]) {
            try {
                PhysicalPosClient::parseWebhookEvent($forgedBody, $forgedHeaders, self::SECRET);
                $this->fail("{$name}: an unverified delivery must not be read");
            } catch (WebhookVerificationException $error) {
                $this->assertNotSame('', $error->getMessage(), $name);
            }
        }
    }

    public function testThereIsNoPathThatSkipsVerification(): void
    {
        $parse = new \ReflectionMethod(PhysicalPosClient::class, 'parseWebhookEvent');
        $types = array_map(static fn (\ReflectionParameter $p): string => (string) $p->getType(), $parse->getParameters());
        $this->assertSame(['string', 'array', 'string', '?int'], $types);

        $event = new \ReflectionClass(PhysicalPosWebhookEvent::class);
        $this->assertFalse($event->getConstructor() !== null && $event->getConstructor()->isPublic());
        $public = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            array_filter($event->getMethods(\ReflectionMethod::IS_STATIC), static fn (\ReflectionMethod $m): bool => $m->isPublic())
        );
        $this->assertSame(['verify'], array_values($public));
    }

    // ── O-4 ─────────────────────────────────────────────────────────────────

    public function testCardNumbersInTextValuesAreRefusedBeforeSending(): void
    {
        [$client, $http] = self::pos();
        $item = ['lineNo' => 1, 'name' => 'Tea', 'quantityMilli' => 1000, 'unitCode' => 'C62', 'unitPriceMinor' => "4500",
            'taxGroupCode' => 'KDV10', 'lineTotalMinor' => "4500"];
        $cases = [
            'merchantReference' => static fn () => $client->createPayment(self::sale(['merchantReference' => '4111 1111 1111 1111']), self::KEY),
            'items[0].name' => static fn () => $client->createPayment(self::sale(['items' => [array_merge($item, ['name' => 'card 4111-1111-1111-1111'])]]), self::KEY),
            'items[0].taxGroupCode' => static fn () => $client->createPayment(self::sale(['items' => [array_merge($item, ['taxGroupCode' => '4111111111111111'])]]), self::KEY),
        ];
        foreach ($cases as $field => $call) {
            try {
                $call();
                $this->fail("{$field}: expected PhysicalPosCardDataRejectedException");
            } catch (PhysicalPosCardDataRejectedException $error) {
                $this->assertSame($field, $error->getField());
                $this->assertTrue($error->isClientSide());
                $this->assertStringNotContainsString('4111', $error->getMessage());
            }
        }
        try {
            $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY', 'merchantReference' => '5500000000000004'], self::KEY);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame('merchantReference', $error->getField());
        }
        try {
            $client->listPayments(['merchantReference' => '4111 1111 1111 1111']);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame('merchantReference', $error->getField());
        }
        [$management, $mhttp] = self::management();
        try {
            $management->listDevices(['q' => '4111111111111111']);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame('q', $error->getField());
        }
        $this->assertSame(0, $http->callCount());
        $this->assertSame(0, $mhttp->callCount());

        // A barcode in an item name and an order number are not card numbers.
        $http->pushJson(202, self::accepted());
        $client->createPayment(self::sale([
            'merchantReference' => 'ORD-4111111111111111',
            'items' => [array_merge($item, ['name' => 'Simit 8691234567012'])],
        ]), self::KEY);
        $this->assertSame(1, $http->callCount());
    }

    // ── DÜŞÜK ───────────────────────────────────────────────────────────────

    public function testDotSegmentIdsAreRefused(): void
    {
        [$client, $http] = self::pos();
        foreach (['.', '..', '', '  '] as $id) {
            try {
                $client->getPayment($id);
                $this->fail('expected ConfigurationException for ' . json_encode($id));
            } catch (ConfigurationException $error) {
                $this->assertSame(0, $http->callCount());
            }
        }
    }

    public function testReportDatesAreTheObjectsOwnCalendarDay(): void
    {
        [$client, $http] = self::pos();
        $http->pushJson(200, ['status' => 'success', 'data' => ['environment' => 'LIVE', 'rows' => []]]);

        $client->getSalesSummary([
            'dateFrom' => new DateTimeImmutable('2026-10-25 23:30:00', new DateTimeZone('America/New_York')),
            'dateTo' => '2026-10-31',
        ]);

        parse_str((string) parse_url($http->lastCall()['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['dateFrom' => '2026-10-25', 'dateTo' => '2026-10-31'], $query);
    }

    public function testEmptyValuesAreNeverSent(): void
    {
        [$client, $http] = self::pos();
        $http->pushJson(202, self::accepted());
        $client->createPayment(self::sale([
            'allowDuplicateReference' => null, 'instrument' => '', 'merchantReference' => '', 'target' => ['deviceId' => 'dev-1', 'vendorCode' => null],
        ]), self::KEY);
        $this->assertSame(
            ['operation' => 'SALE', 'target' => ['deviceId' => 'dev-1'], 'amountMinor' => "4500", 'currency' => 'TRY'],
            self::bodyOf($http->lastCall())
        );

        [$management, $mhttp] = self::management();
        $mhttp->pushJson(200, ['status' => 'success', 'data' => ['store' => ['id' => 's1']]]);
        $management->updateStore('s1', ['rowVersion' => 2, 'name' => null, 'city' => '', 'district' => null]);
        $this->assertSame(['rowVersion' => 2, 'district' => null], self::bodyOf($mhttp->lastCall()));

        try {
            $management->createStore(['code' => '', 'name' => 'B']);
            $this->fail('an empty required field is refused');
        } catch (ConfigurationException $error) {
            $this->assertSame(1, $mhttp->callCount());
        }
    }

    public function testMissingAmountIsNullNotZero(): void
    {
        $payment = new PhysicalPosPayment(['paymentId' => self::PAYMENT_ID]);
        $this->assertNull($payment->amountMinor());
        $this->assertNull($payment->reversedMinor());
        $this->assertSame('0', (new PhysicalPosPayment(['amountMinor' => "0"]))->amountMinor());
    }

    public function testRetryAfterHeaderIsReadOnManagementAndCoreErrors(): void
    {
        [$management, $http] = self::management();
        $http->pushJson(429, self::failure(429, 'RATE_LIMITED'), ['retry-after' => '42']);
        try {
            $management->createStore(['code' => 'A', 'name' => 'B'], self::KEY);
            $this->fail('expected PhysicalPosRateLimitException');
        } catch (PhysicalPosRateLimitException $error) {
            $this->assertSame(42, $error->getRetryAfter());
            $this->assertSame('42', $error->getResponseHeader('Retry-After'));
        }

        $legacy = new ApiException('x', 500, 'c', null, null);
        $this->assertSame([], $legacy->getResponseHeaders(), 'six-argument construction still works');
        $this->assertNull($legacy->getRetryAfter());
    }

    public function testABodyThatCannotBeEncodedIsNeverSentEmpty(): void
    {
        [$management, $http] = self::management();
        try {
            $management->createStore(['code' => 'A', 'name' => "bad \xff utf-8"], self::KEY);
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException $error) {
            $this->assertStringNotContainsString("\xff", $error->getMessage());
        }
        $this->assertSame(0, $http->callCount());
    }
}
