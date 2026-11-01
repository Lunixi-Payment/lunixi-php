<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\PhysicalPos;

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
use Lunixi\Sdk\Exception\PhysicalPosVoidAfterSettlementException;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosEvents;
use Lunixi\Sdk\PhysicalPos\PhysicalPosManagementClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosPayment;
use Lunixi\Sdk\PhysicalPos\PhysicalPosPaymentOperation;
use Lunixi\Sdk\PhysicalPos\PhysicalPosPaymentStatus;
use Lunixi\Sdk\PhysicalPos\PhysicalPosRequest;
use Lunixi\Sdk\PhysicalPos\PhysicalPosReversalStatus;
use Lunixi\Sdk\PhysicalPos\PhysicalPosSettlementLine;
use Lunixi\Sdk\PhysicalPos\PhysicalPosTransactionTree;
use Lunixi\Sdk\PhysicalPos\PhysicalPosWebhookEvent;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\SignatureGuardStandIn;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract source: nest-payment-gateway @ 8d28433e2 (origin/main)
 * apps/api-gateway/src/physical-pos/payments/** (physical-pos-payments-api.controller.ts,
 * dtos/physical-pos-payments.dto.ts, physical-pos-payments.service.ts, physical-pos-payments.mapper.ts,
 * physical-pos-card-data.guard.ts), transactions/** (api controller, dto, mapper);
 * libs/proto/src/physical-pos-card-data.ts, physical-pos-webhook.interface.ts;
 * apps/webhook-service/src/deliveries/services/webhook-delivery-ingestion.service.ts.
 * Ids and values below are synthetic.
 */
final class PhysicalPosClientTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';
    private const BASE = '/api/v1/physical-pos';
    private const PAYMENT_ID = '0192f3a1-0000-7000-8000-000000000001';
    private const CHILD_ID = '0192f3a1-0000-7000-8000-000000000002';
    private const DEVICE_ID = '3f0e8d1c-2a90-4c55-9a51-7b0c6a1e2f8d';
    private const KEY = 'pos-test-key-0001';
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $publicKey = '';

    /** @return array{0:PhysicalPosClient,1:FakeHttpClient} */
    private function make(int $maxRetries = 0): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $this->publicKey = SignatureGuardStandIn::rawPublicKey($keys['publicKey']);

        $config = new Configuration([
            'baseUrl' => self::BASE_URL,
            'keyId' => 'mk_live_sk_kid_1',
            'privateKey' => $keys['privateKey'],
            'maxRetries' => $maxRetries,
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $store->set($config->tokenCacheKey(), 'at_test_seeded', 3600);

        return [new PhysicalPosClient(new ApiClient($config, $signer, new TokenManager($config, $signer, $http, $store), $http)), $http];
    }

    /** @param array{method:string,url:string,headers:array<string,string>,body:?string} $call */
    private function verdict(array $call): string
    {
        return SignatureGuardStandIn::verdict($call, $this->publicKey, self::BASE_URL);
    }

    /** @return array<string,mixed> */
    private static function envelope(array $data, int $statusCode = 200, array $extra = []): array
    {
        return array_merge(
            ['status' => 'success', 'code' => 'SUCCESS', 'data' => $data, 'statusCode' => $statusCode, 'requestId' => 'req_1'],
            $extra
        );
    }

    /** List envelope: rows in `data.items`, paging in the top-level `pageInfo` (copy inside `data`). */
    private static function page(array $items, bool $hasMore = false, ?string $cursor = null): array
    {
        $pageInfo = ['hasMore' => $hasMore, 'nextCursor' => $cursor, 'totalCount' => null];

        return self::envelope(['items' => $items, 'pageInfo' => $pageInfo], 200, ['pageInfo' => $pageInfo]);
    }

    /** @return array<string,mixed> */
    private static function failure(int $status, string $code, array $details = []): array
    {
        return [
            'status' => 'failure', 'code' => $code, 'message' => 'Rejected.', 'statusCode' => $status,
            'requestId' => 'req_err', 'details' => $details,
        ];
    }

    /** @param array{url:string} $call */
    private static function target(array $call): string
    {
        return substr($call['url'], strlen(self::BASE_URL));
    }

    /** @param array{url:string} $call @return array<string,string> */
    private static function queryOf(array $call): array
    {
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    /** @param array{body:?string} $call @return mixed */
    private static function bodyOf(array $call)
    {
        return $call['body'] === null ? null : json_decode($call['body'], true);
    }

    /** Exactly the fields of the gateway's paymentToJson(). */
    private static function paymentJson(array $overrides = []): array
    {
        return array_merge([
            'paymentId' => self::PAYMENT_ID, 'rootPaymentId' => self::PAYMENT_ID, 'originalPaymentId' => null,
            'origin' => 'API', 'operation' => 'SALE', 'paymentStatus' => 'NOT_STARTED', 'statusReason' => null,
            'outcomeConflict' => false, 'resolvable' => false, 'deviceId' => self::DEVICE_ID, 'logicalTerminalId' => null,
            'terminalAcquirerId' => null, 'channel' => 'APP2APP', 'vendorReference' => str_replace('-', '', self::PAYMENT_ID),
            'amountMinor' => "4500", 'approvedAmountMinor' => null, 'currency' => 'TRY', 'installmentCount' => null,
            'instrument' => 'CARD', 'merchantReference' => 'ORD-1', 'reversedMinor' => "0", 'capturedMinor' => "0",
            'reversalReservedMinor' => "0", 'pendingReversalId' => null, 'reversalStatus' => 'NONE',
            'expiresAt' => '2026-10-30T09:00:30.000Z', 'resultDeadlineAt' => '2026-10-30T09:03:30.000Z', 'completedAt' => null,
            'createdAt' => '2026-10-30T09:00:00.000Z', 'updatedAt' => '2026-10-30T09:00:00.000Z', 'rowVersion' => 1,
            'historySeq' => 1, 'tender' => null, 'items' => [], 'refundItems' => [],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function saleRequest(): array
    {
        return [
            'operation' => 'SALE',
            'target' => ['vendorCode' => 'PAVO', 'serialNumber' => 'PAV00012345'],
            'amountMinor' => "4500",
            'currency' => 'TRY',
            'merchantReference' => 'ORD-1',
            'items' => [
                ['lineNo' => 1, 'name' => 'Tea', 'quantityMilli' => 2000, 'unitCode' => 'C62', 'unitPriceMinor' => "1500",
                    'taxGroupCode' => 'KDV10', 'lineTotalMinor' => "3000", 'barcode' => '8691234567012'],
                ['lineNo' => 2, 'name' => 'Bagel', 'quantityMilli' => 1000, 'unitCode' => 'C62', 'unitPriceMinor' => "1500",
                    'taxGroupCode' => 'KDV1', 'lineTotalMinor' => "1500"],
            ],
        ];
    }

    // ── Payments: start, 202 / 200, idempotency ─────────────────────────────

    public function testCreatePaymentSendsSignedBodyWithTheCallerKeyAnd202(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(202, self::envelope(['payment' => self::paymentJson(), 'replayed' => false], 202));

        $key = PhysicalPosClient::newIdempotencyKey();
        $result = $client->createPayment(self::saleRequest(), $key);

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE . '/payments', self::target($call));
        $this->assertSame('OK', $this->verdict($call));
        $this->assertMatchesRegularExpression(self::UUID_V4, $key);
        $this->assertSame($key, $call['headers']['Idempotency-Key']);
        $this->assertSame(self::saleRequest(), self::bodyOf($call));

        $this->assertSame(202, $result->statusCode());
        $this->assertTrue($result->accepted());
        $this->assertFalse($result->replayed());
        $this->assertSame($call['headers']['Idempotency-Key'], $result->idempotencyKey());
        $this->assertSame(self::PAYMENT_ID, $result->payment()->paymentId());
        $this->assertSame(PhysicalPosPaymentStatus::NOT_STARTED, $result->payment()->paymentStatus());
        $this->assertFalse($result->payment()->isFinal());
    }

    public function testReplayOfTheSameKeyIs200WithTheCurrentView(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope([
            'payment' => self::paymentJson(['paymentStatus' => 'APPROVED', 'approvedAmountMinor' => "4500"]),
            'replayed' => true,
        ], 200));

        $result = $client->createPayment(self::saleRequest(), self::KEY);

        $this->assertSame(self::KEY, $http->lastCall()['headers']['Idempotency-Key']);
        $this->assertSame(200, $result->statusCode());
        $this->assertFalse($result->accepted());
        $this->assertTrue($result->replayed());
        $this->assertSame(self::KEY, $result->idempotencyKey());
        $this->assertSame('4500', $result->payment()->approvedAmountMinor());
        $this->assertTrue($result->payment()->isFinal());
    }

    public function testStatusCodeFallsBackToReplayedWhenTheEnvelopeHasNone(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(202, ['status' => 'success', 'data' => ['payment' => self::paymentJson(), 'replayed' => false]]);
        $this->assertSame(202, $client->createPayment(self::saleRequest(), self::KEY)->statusCode());

        $http->pushJson(200, ['status' => 'success', 'data' => ['payment' => self::paymentJson(), 'replayed' => true]]);
        $this->assertSame(200, $client->createPayment(self::saleRequest(), self::KEY)->statusCode());
    }

    public function testRetryOfANotStartedAnswerReusesTheSameKeyAndBody(): void
    {
        [$client, $http] = $this->make(1);
        $http->pushJson(503, self::failure(503, 'physical_pos.service.busy', ['retryable' => true]));
        $http->pushJson(202, self::envelope(['payment' => self::paymentJson(), 'replayed' => false], 202));

        $result = $client->createPayment(self::saleRequest(), self::KEY);

        $this->assertSame(2, $http->callCount());
        $this->assertSame(self::KEY, $http->calls[0]['headers']['Idempotency-Key']);
        $this->assertSame(self::KEY, $http->calls[1]['headers']['Idempotency-Key']);
        $this->assertSame($http->calls[0]['body'], $http->calls[1]['body']);
        $this->assertSame(self::KEY, $result->idempotencyKey());
    }

    /** @return array<string,array{0:string|null}> */
    public static function invalidKeys(): array
    {
        return [
            'too short' => ['short'], 'space' => ['has a space 123'], 'non-ascii' => ['anahtar-çok-uzun'],
            'too long' => [str_repeat('k', 201)], 'empty' => [''], 'blank' => ['          '], 'padded' => [' ' . self::KEY],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidIdempotencyKeyIsRefusedBeforeSending(string $key): void
    {
        [$client, $http] = $this->make();
        try {
            $client->createPayment(self::saleRequest(), $key);
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException $error) {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testPaymentStartAndReversalRequireAKey(): void
    {
        [$client, $http] = $this->make();
        foreach ([
            static fn () => $client->createPayment(self::saleRequest()),
            static fn () => $client->createPayment(self::saleRequest(), null),
            static fn () => $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY']),
        ] as $call) {
            try {
                $call();
                $this->fail('expected ConfigurationException');
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString('newIdempotencyKey', $error->getMessage());
            }
        }
        $this->assertSame(0, $http->callCount());
        $this->assertNotSame(PhysicalPosClient::newIdempotencyKey(), PhysicalPosClient::newIdempotencyKey());
    }

    // ── Outcome unknown, rate limit, card data ──────────────────────────────

    /** @return array<string,array{0:\Closure(FakeHttpClient):void}> */
    public static function uncertainAnswers(): array
    {
        return [
            'network' => [static function (FakeHttpClient $http): void {
                $http->push(new ApiException('HTTP transport error (28): timeout'));
            }],
            'untyped 503' => [static function (FakeHttpClient $http): void {
                $http->pushJson(503, self::failure(503, 'SERVICE_UNAVAILABLE', ['retryAfterSeconds' => 2]));
            }],
            'non-json 2xx' => [static function (FakeHttpClient $http): void {
                $http->push(new \Lunixi\Sdk\Http\HttpResponse(202, [], '<html>accepted</html>'));
            }],
            '504 gateway timeout' => [static function (FakeHttpClient $http): void {
                $http->pushJson(504, ['message' => 'Gateway Timeout']);
            }],
            '500' => [static function (FakeHttpClient $http): void {
                $http->pushJson(500, self::failure(500, 'physical_pos.internal.integrity'));
            }],
        ];
    }

    #[DataProvider('uncertainAnswers')]
    public function testUncertainWriteOutcomeCarriesTheKeyToRetryWith(\Closure $answer): void
    {
        [$client, $http] = $this->make();
        $answer($http);

        try {
            $client->createPayment(self::saleRequest(), self::KEY);
            $this->fail('expected PhysicalPosOutcomeUnknownException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->assertSame(self::KEY, $error->getIdempotencyKey());
            $this->assertInstanceOf(ApiException::class, $error->getPrevious());
        }
    }

    public function testOutcomeUnknownOnReversalAndCancelToo(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(503, self::failure(503, 'SERVICE_UNAVAILABLE'));
        $http->pushJson(502, ['message' => 'Bad Gateway']);

        try {
            $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY'], self::KEY);
            $this->fail('expected PhysicalPosOutcomeUnknownException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->assertSame(self::KEY, $error->getIdempotencyKey());
        }
        try {
            $client->cancelPayment(self::PAYMENT_ID, self::KEY);
            $this->fail('expected PhysicalPosOutcomeUnknownException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->assertSame(self::KEY, $error->getIdempotencyKey());
        }
    }

    public function testDefiniteRejectionIsNotOutcomeUnknown(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(409, self::failure(409, 'physical_pos.payment.device_busy', ['reason' => 'payment_in_progress', 'retryAfterSeconds' => 30]));

        try {
            $client->createPayment(self::saleRequest(), self::KEY);
            $this->fail('expected ApiException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->fail('a 409 is a definite answer');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame('physical_pos.payment.device_busy', $error->getErrorCode());
        }
    }

    public function testVoidAfterSettlementIsTyped(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(409, self::failure(409, 'physical_pos.payment.void_after_settlement', ['allowedOperation' => 'REFUND']));

        try {
            $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY'], self::KEY);
            $this->fail('expected PhysicalPosVoidAfterSettlementException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->fail('a 409 is a definite answer');
        } catch (PhysicalPosVoidAfterSettlementException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame(PhysicalPosVoidAfterSettlementException::CODE, $error->getErrorCode());
            $this->assertSame('REFUND', $error->getAllowedOperation());
        }
        $this->assertCount(1, $http->calls, 'a definite 409 is not retried');

        $http->pushJson(409, self::failure(409, 'physical_pos.payment.void_after_settlement'));
        try {
            $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY'], self::KEY);
            $this->fail('expected PhysicalPosVoidAfterSettlementException');
        } catch (PhysicalPosVoidAfterSettlementException $error) {
            $this->assertNull($error->getAllowedOperation());
        }
    }

    public function testRateLimitCarriesRetryAfterSeconds(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(429, self::failure(429, 'RATE_LIMITED', ['retryAfterSeconds' => 17]), ['retry-after' => '17']);

        try {
            $client->createPayment(self::saleRequest(), self::KEY);
            $this->fail('expected PhysicalPosRateLimitException');
        } catch (PhysicalPosRateLimitException $error) {
            $this->assertSame(429, $error->getStatusCode());
            $this->assertSame('RATE_LIMITED', $error->getErrorCode());
            $this->assertSame(17, $error->getRetryAfter());
        }
    }

    public function testRateLimitOnAReadIsTypedToo(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(429, self::failure(429, 'RATE_LIMITED'));

        $this->expectException(PhysicalPosRateLimitException::class);
        $client->listPayments();
    }

    public function testServerCardDataRejectionIsTyped(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(400, self::failure(400, 'physical_pos.payment.card_data_rejected', ['field' => 'items[1].name']));

        try {
            $client->createPayment(self::saleRequest(), self::KEY);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame(400, $error->getStatusCode());
            $this->assertSame('items[1].name', $error->getField());
            $this->assertFalse($error->isClientSide());
        }
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function cardDataRequests(): array
    {
        $sale = self::saleRequest();

        return [
            'pan' => [array_merge($sale, ['pan' => 'x']), 'pan'],
            'cardNumber' => [array_merge($sale, ['cardNumber' => 'x']), 'cardNumber'],
            'snake card_number' => [array_merge($sale, ['card_number' => 'x']), 'card_number'],
            'cvv' => [array_merge($sale, ['cvv' => 'x']), 'cvv'],
            'CVVCode' => [array_merge($sale, ['CVVCode' => 'x']), 'CVVCode'],
            'expiryDate' => [array_merge($sale, ['expiryDate' => 'x']), 'expiryDate'],
            'expiry' => [array_merge($sale, ['expiry' => 'x']), 'expiry'],
            'track2Data' => [array_merge($sale, ['track2Data' => 'x']), 'track2Data'],
            'vendor prefix' => [array_merge($sale, ['customercardnumber' => 'x']), 'customercardnumber'],
            'pin' => [array_merge($sale, ['pin' => 'x']), 'pin'],
            'in target' => [array_merge($sale, ['target' => ['deviceId' => self::DEVICE_ID, 'pan' => 'x']]), 'target.pan'],
            'in an item' => [
                array_merge($sale, ['items' => [$sale['items'][0], array_merge($sale['items'][1], ['cvv' => 'x'])]]),
                'items[1].cvv',
            ],
            'number as key' => [array_merge($sale, ['4543 6000 0000 1234' => 'x']), '(body)'],
        ];
    }

    #[DataProvider('cardDataRequests')]
    public function testCardDataKeyIsRefusedBeforeSending(array $request, string $field): void
    {
        [$client, $http] = $this->make();
        try {
            $client->createPayment($request);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame($field, $error->getField());
            $this->assertTrue($error->isClientSide());
            $this->assertSame(PhysicalPosCardDataRejectedException::CODE, $error->getErrorCode());
            $this->assertStringNotContainsString('4543', $error->getMessage());
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testCardDataKeyInAReversalAndInFiltersIsRefused(): void
    {
        [$client, $http] = $this->make();
        try {
            $client->createReversal(self::PAYMENT_ID, ['operation' => 'REFUND', 'currency' => 'TRY', 'amountMinor' => "100", 'cvc2' => 'x']);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame('cvc2', $error->getField());
        }
        try {
            $client->listPayments(['pan' => 'x']);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame('pan', $error->getField());
        }
        $this->assertSame(0, $http->callCount());
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function keyNames(): array
    {
        return [
            'panel' => ['panel', false], 'company' => ['company', false], 'span' => ['span', false],
            'expiresAt' => ['expiresAt', false], 'trackingNo' => ['trackingNo', false], 'pinKeyKcv' => ['pinKeyKcv', false],
            'tokenExpiry' => ['tokenExpiry', false], 'maskedPan' => ['maskedPan', false], 'cardMasked' => ['cardMasked', false],
            'merchantReference' => ['merchantReference', false], 'quantityMilli' => ['quantityMilli', false],
            'cardNumberMasked' => ['cardNumberMasked', false],
            'PAN' => ['PAN', true], 'card-number' => ['card-number', true], 'securityCode' => ['securityCode', true],
            'expMonth' => ['expMonth', true], 'maskedCvv' => ['maskedCvv', true], 'trackII' => ['trackII', true],
        ];
    }

    #[DataProvider('keyNames')]
    public function testCardDataKeyRuleMatchesTheGatewayTokenRule(string $key, bool $isCardData): void
    {
        $this->assertSame($isCardData, PhysicalPosRequest::isCardDataKey($key));
    }

    public function testEveryRequestFieldNameIsNotCardData(): void
    {
        $names = array_merge(
            PhysicalPosClient::PAYMENT_FIELDS,
            PhysicalPosClient::REVERSAL_FIELDS,
            PhysicalPosClient::TARGET_FIELDS,
            PhysicalPosClient::SALE_ITEM_FIELDS,
            PhysicalPosClient::REFUND_ITEM_FIELDS,
            ...array_values(PhysicalPosClient::FILTERS),
            ...array_values(PhysicalPosManagementClient::FILTERS)
        );
        foreach (array_keys(self::paymentJson()) as $name) {
            $names[] = $name;
        }
        foreach (array_unique($names) as $name) {
            $this->assertFalse(PhysicalPosRequest::isCardDataKey($name), $name);
        }
    }

    // ── Request shape ───────────────────────────────────────────────────────

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badPaymentRequests(): array
    {
        $sale = self::saleRequest();
        $without = static function (string $field) use ($sale): array {
            unset($sale[$field]);
            return $sale;
        };

        return [
            'unknown field' => [array_merge($sale, ['note' => 'x'])],
            'environment' => [array_merge($sale, ['environment' => 'LIVE'])],
            'organizationId' => [array_merge($sale, ['organizationId' => 'x'])],
            'amount as number' => [array_merge($sale, ['amountMinor' => 4500])],
            'amount as float' => [array_merge($sale, ['amountMinor' => 45.0])],
            'missing operation' => [$without('operation')],
            'missing target' => [$without('target')],
            'missing amount' => [$without('amountMinor')],
            'missing currency' => [$without('currency')],
            'target not an object' => [array_merge($sale, ['target' => 'device-1'])],
            'unknown target field' => [array_merge($sale, ['target' => ['terminalCode' => 'T1']])],
            'empty items' => [array_merge($sale, ['items' => []])],
            'unknown item field' => [array_merge($sale, ['items' => [array_merge($sale['items'][0], ['vatRate' => 10])]])],
            'item quantity as string' => [array_merge($sale, ['items' => [array_merge($sale['items'][0], ['quantityMilli' => '2000'])]])],
            'item missing lineTotalMinor' => [(static function () use ($sale): array {
                unset($sale['items'][0]['lineTotalMinor']);
                return $sale;
            })()],
            'allowDuplicateReference as string' => [array_merge($sale, ['allowDuplicateReference' => 'true'])],
        ];
    }

    #[DataProvider('badPaymentRequests')]
    public function testMalformedPaymentRequestIsRefusedBeforeSending(array $request): void
    {
        [$client, $http] = $this->make();
        try {
            $client->createPayment($request, self::KEY);
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException $error) {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testValueRulesAreLeftToTheGateway(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(422, self::failure(422, 'physical_pos.payment.operation_unsupported', ['reason' => 'operation_not_root']));

        try {
            $client->createPayment(array_merge(self::saleRequest(), ['operation' => 'CASH', 'amountMinor' => "4500"]), self::KEY);
            $this->fail('expected ApiException');
        } catch (ApiException $error) {
            $this->assertSame('physical_pos.payment.operation_unsupported', $error->getErrorCode());
            $this->assertSame(1, $http->callCount());
        }
    }

    public function testNullablePaymentFieldsAreSentAsNull(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(202, self::envelope(['payment' => self::paymentJson(), 'replayed' => false], 202));

        $client->createPayment([
            'operation' => PhysicalPosPaymentOperation::SALE, 'target' => ['deviceId' => self::DEVICE_ID], 'amountMinor' => "100",
            'currency' => 'TRY', 'installmentCount' => null, 'merchantReference' => null, 'terminalAcquirerId' => null,
            'allowDuplicateReference' => true, 'startTimeoutSeconds' => 20,
        ], self::KEY);

        $this->assertSame([
            'operation' => 'SALE', 'target' => ['deviceId' => self::DEVICE_ID], 'amountMinor' => "100", 'currency' => 'TRY',
            'installmentCount' => null, 'merchantReference' => null, 'allowDuplicateReference' => true,
            'startTimeoutSeconds' => 20, 'terminalAcquirerId' => null,
        ], self::bodyOf($http->lastCall()));
    }

    // ── Reversal, cancel ────────────────────────────────────────────────────

    public function testItemBasedRefundCreatesAChild(): void
    {
        [$client, $http] = $this->make();
        $child = self::paymentJson([
            'paymentId' => self::CHILD_ID, 'originalPaymentId' => self::PAYMENT_ID, 'operation' => 'REFUND', 'amountMinor' => "1500",
        ]);
        $http->pushJson(202, self::envelope(['payment' => $child, 'replayed' => false], 202));

        $result = $client->createReversal(self::PAYMENT_ID, [
            'operation' => PhysicalPosPaymentOperation::REFUND, 'currency' => 'TRY',
            'items' => [['lineNo' => 1, 'quantityMilli' => 1000]],
        ], self::KEY);

        $call = $http->lastCall();
        $this->assertSame(self::BASE . '/payments/' . self::PAYMENT_ID . '/reversals', self::target($call));
        $this->assertSame('OK', $this->verdict($call));
        $this->assertSame(self::KEY, $call['headers']['Idempotency-Key']);
        $this->assertSame(['operation' => 'REFUND', 'currency' => 'TRY', 'items' => [['lineNo' => 1, 'quantityMilli' => 1000]]], self::bodyOf($call));
        $this->assertSame(202, $result->statusCode());
        $this->assertFalse($result->payment()->isRoot());
        $this->assertSame(self::PAYMENT_ID, $result->payment()->originalPaymentId());
        $this->assertSame('1500', $result->payment()->amountMinor());
    }

    public function testVoidSendsOnlyOperationAndCurrency(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(202, self::envelope(['payment' => self::paymentJson(['operation' => 'VOID']), 'replayed' => false], 202));

        $client->createReversal(self::PAYMENT_ID, ['operation' => 'VOID', 'currency' => 'TRY'], self::KEY);

        $this->assertSame(['operation' => 'VOID', 'currency' => 'TRY'], self::bodyOf($http->lastCall()));
    }

    public function testReversalRequestShapeIsChecked(): void
    {
        [$client, $http] = $this->make();
        foreach ([
            ['operation' => 'REFUND', 'currency' => 'TRY', 'items' => [['lineNo' => 1, 'quantityMilli' => 1000, 'name' => 'x']]],
            ['operation' => 'REFUND', 'currency' => 'TRY', 'installmentCount' => 2],
            ['operation' => 'VOID'],
            ['operation' => 'REFUND', 'currency' => 'TRY', 'amountMinor' => 100],
        ] as $request) {
            try {
                $client->createReversal(self::PAYMENT_ID, $request, self::KEY);
                $this->fail('expected ConfigurationException');
            } catch (ConfigurationException $error) {
                $this->assertSame(0, $http->callCount());
            }
        }
        $this->expectException(ConfigurationException::class);
        $client->createReversal(' ', ['operation' => 'VOID', 'currency' => 'TRY'], self::KEY);
    }

    public function testCancelSendsAnEmptyObjectWithAKeyAndIs200(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope([
            'payment' => self::paymentJson(['paymentStatus' => 'EXPIRED', 'statusReason' => 'MERCHANT_CANCELLED_BEFORE_DISPATCH']),
            'replayed' => false,
        ], 200));

        $result = $client->cancelPayment(self::PAYMENT_ID);

        $call = $http->lastCall();
        $this->assertSame(self::BASE . '/payments/' . self::PAYMENT_ID . '/cancel', self::target($call));
        $this->assertSame('{}', $call['body']);
        $this->assertSame('OK', $this->verdict($call));
        $this->assertMatchesRegularExpression(self::UUID_V4, $call['headers']['Idempotency-Key']);
        $this->assertSame(200, $result->statusCode());
        $this->assertSame('MERCHANT_CANCELLED_BEFORE_DISPATCH', $result->payment()->statusReason());
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    public function testGetPaymentReadsItemsAndTender(): void
    {
        [$client, $http] = $this->make();
        $item = [
            'lineNo' => 1, 'name' => 'Tea', 'quantityMilli' => 2000, 'unitCode' => 'C62', 'unitPriceMinor' => "1500",
            'taxGroupCode' => 'KDV10', 'lineTotalMinor' => "3000", 'netMinor' => "2727", 'refundedQuantityMilli' => 0,
            'refundedMinor' => "0", 'reservedQuantityMilli' => 0, 'reservedMinor' => "0", 'refundStatus' => 'NONE',
            'taxes' => [['taxType' => 'KDV', 'taxMode' => 'PERCENT', 'rateBp' => 1000, 'unitAmountMinor' => null, 'baseMinor' => "2727",
                'amountMinor' => "273", 'reasonCode' => null, 'refundedAmountMinor' => "0", 'reservedAmountMinor' => "0"]],
            'barcode' => '8691234567012',
        ];
        $http->pushJson(200, self::envelope(['payment' => self::paymentJson([
            'paymentStatus' => 'APPROVED', 'approvedAmountMinor' => "4500", 'installmentCount' => 3,
            'tender' => ['tenderStatus' => 'APPROVED', 'cardMasked' => '454360******1234', 'cardBrand' => 'VISA',
                'entryMode' => 'CONTACTLESS', 'acquirerCode' => null, 'authCode' => 'A1B2C3', 'rrn' => null],
            'items' => [$item],
        ])]));

        $payment = $client->getPayment(self::PAYMENT_ID);

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame(self::BASE . '/payments/' . self::PAYMENT_ID, self::target($call));
        $this->assertSame('OK', $this->verdict($call));
        $this->assertArrayNotHasKey('Idempotency-Key', $call['headers']);
        $this->assertSame(3, $payment->installmentCount());
        $this->assertSame('454360******1234', $payment->tender()['cardMasked']);
        $this->assertSame([$item], $payment->items());
        $this->assertSame(PhysicalPosReversalStatus::NONE, $payment->reversalStatus());
        $this->assertNull($payment->completedAt());
    }

    public function testListPaymentsSendsFiltersAndReadsPaging(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::paymentJson()], true, 'cur_2'));

        $page = $client->listPayments([
            'limit' => 10, 'deviceId' => self::DEVICE_ID, 'paymentStatus' => 'UNKNOWN', 'merchantReference' => 'ORD-1',
            'createdFrom' => new \DateTimeImmutable('2026-10-01T03:00:00+03:00'), 'createdTo' => '2026-10-30T00:00:00.000Z',
        ]);

        $call = $http->lastCall();
        $this->assertSame('OK', $this->verdict($call));
        $this->assertSame([
            'limit' => '10', 'deviceId' => self::DEVICE_ID, 'paymentStatus' => 'UNKNOWN',
            'createdFrom' => '2026-10-01T00:00:00.000Z', 'createdTo' => '2026-10-30T00:00:00.000Z', 'merchantReference' => 'ORD-1',
        ], self::queryOf($call));
        $this->assertCount(1, $page->items());
        $this->assertInstanceOf(PhysicalPosPayment::class, $page->items()[0]);
        $this->assertTrue($page->hasMore());
        $this->assertSame('cur_2', $page->nextCursor());
        $this->assertNull($page->totalCount());
    }

    public function testIteratorFollowsTheCursor(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::paymentJson()], true, 'cur_2'));
        $http->pushJson(200, self::page([self::paymentJson(['paymentId' => self::CHILD_ID])], false, null));

        $ids = [];
        foreach ($client->iteratePayments(['limit' => 1]) as $payment) {
            $ids[] = $payment->paymentId();
        }

        $this->assertSame([self::PAYMENT_ID, self::CHILD_ID], $ids);
        $this->assertArrayNotHasKey('cursor', self::queryOf($http->calls[0]));
        $this->assertSame('cur_2', self::queryOf($http->calls[1])['cursor']);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badFilters(): array
    {
        return [
            'unknown' => [['operation' => 'SALE']],
            'environment' => [['environment' => 'LIVE']],
            'limit 0' => [['limit' => 0]],
            'limit 101' => [['limit' => 101]],
            'limit as string' => [['limit' => '10']],
            'status not a string' => [['paymentStatus' => 1]],
        ];
    }

    #[DataProvider('badFilters')]
    public function testBadFiltersAreRefusedBeforeSending(array $filters): void
    {
        [$client, $http] = $this->make();
        try {
            $client->listPayments($filters);
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException $error) {
            $this->assertSame(0, $http->callCount());
        }
    }

    // ── Transactions, reports, settlements ──────────────────────────────────

    public function testTransactionsListAndDetail(): void
    {
        [$client, $http] = $this->make();
        $tree = [
            'root' => self::paymentJson(['paymentStatus' => 'APPROVED']),
            'children' => [self::paymentJson(['paymentId' => self::CHILD_ID, 'originalPaymentId' => self::PAYMENT_ID, 'operation' => 'VOID'])],
            'childrenTruncated' => false,
            'itemSummary' => ['lineCount' => 2, 'refundStatus' => 'NONE', 'refundedMinor' => "0"],
            'storeId' => null, 'registerId' => null,
        ];
        $http->pushJson(200, self::page([$tree]));
        $http->pushJson(200, self::envelope(['transaction' => [
            'requestedPaymentId' => self::CHILD_ID, 'root' => $tree['root'], 'children' => $tree['children'],
            'timeline' => [['kind' => 'PAYMENT', 'paymentId' => self::PAYMENT_ID, 'commandId' => null, 'commandType' => null, 'seq' => 1,
                'eventType' => 'payment.created', 'fromStatus' => null, 'toStatus' => 'NOT_STARTED', 'reasonCode' => null, 'source' => 'API',
                'actorType' => 'API_KEY', 'evidenceStrength' => 'NONE', 'occurredAt' => null, 'recordedAt' => '2026-10-30T09:00:00.000Z']],
            'timelineTruncated' => false, 'childrenTruncated' => false, 'storeId' => null, 'registerId' => null,
        ]]));

        $page = $client->listTransactions([
            'amountFromMinor' => "100", 'amountToMinor' => "5000", 'origin' => 'DEVICE_DIRECT', 'operation' => 'SALE', 'storeId' => 's1',
        ]);
        $first = $http->calls[0];
        $this->assertSame(self::BASE . '/transactions', strtok(self::target($first), '?'));
        $this->assertSame(
            ['storeId' => 's1', 'amountFromMinor' => '100', 'amountToMinor' => '5000', 'origin' => 'DEVICE_DIRECT', 'operation' => 'SALE'],
            self::queryOf($first)
        );
        $row = $page->items()[0];
        $this->assertInstanceOf(PhysicalPosTransactionTree::class, $row);
        $this->assertSame(PhysicalPosPaymentStatus::APPROVED, $row->root()->paymentStatus());
        $this->assertSame('VOID', $row->children()[0]->operation());
        $this->assertSame(2, $row->itemSummary()['lineCount']);

        $detail = $client->getTransaction(self::CHILD_ID);
        $this->assertSame(self::BASE . '/transactions/' . self::CHILD_ID, self::target($http->lastCall()));
        $this->assertSame(self::CHILD_ID, $detail->requestedPaymentId());
        $this->assertSame(self::PAYMENT_ID, $detail->root()->paymentId());
        $this->assertSame('NOT_STARTED', $detail->timeline()[0]['toStatus']);
    }

    public function testAmbiguousPaymentsList(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::paymentJson(['paymentStatus' => 'MANUAL_REVIEW', 'resolvable' => true])]));

        $page = $client->listAmbiguousPayments(['paymentStatus' => 'MANUAL_REVIEW']);

        $this->assertSame(self::BASE . '/transactions/ambiguous?paymentStatus=MANUAL_REVIEW', self::target($http->lastCall()));
        $this->assertTrue($page->items()[0]->resolvable());
    }

    public function testReports(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope([
            'environment' => 'LIVE', 'dateFrom' => '2026-10-24', 'dateTo' => '2026-10-30', 'groupBy' => 'STORE',
            'rows' => [['groupKey' => null, 'currency' => 'TRY', 'saleCount' => 2, 'saleMinor' => "9000", 'refundCount' => 1,
                'refundMinor' => "1500", 'voidCount' => 0, 'voidMinor' => "0", 'netMinor' => "7500", 'ambiguousCount' => 1]],
        ]));
        $http->pushJson(200, self::envelope([
            'environment' => 'LIVE', 'dateFrom' => '2026-10-24', 'dateTo' => '2026-10-30',
            'rows' => [['taxType' => 'KDV', 'rateBp' => 1000, 'currency' => 'TRY', 'saleBaseMinor' => "2727", 'saleTaxMinor' => "273",
                'refundBaseMinor' => "0", 'refundTaxMinor' => "0", 'voidBaseMinor' => "0", 'voidTaxMinor' => "0", 'netBaseMinor' => "2727", 'netTaxMinor' => "273"]],
            'amountTotals' => [['currency' => 'TRY', 'scope' => 'ITEMIZED', 'saleCount' => 1, 'saleMinor' => "3000", 'refundCount' => 0,
                'refundMinor' => "0", 'voidCount' => 0, 'voidMinor' => "0", 'netMinor' => "3000"]],
        ]));

        $sales = $client->getSalesSummary(['groupBy' => 'STORE', 'dateFrom' => '2026-10-24', 'dateTo' => '2026-10-30']);
        $this->assertSame(self::BASE . '/reports/sales-summary', strtok(self::target($http->lastCall()), '?'));
        $this->assertSame('STORE', self::queryOf($http->lastCall())['groupBy']);
        $this->assertSame('LIVE', $sales->environment());
        $this->assertNull($sales->rows()[0]['groupKey']);
        $this->assertSame('7500', $sales->rows()[0]['netMinor']);

        $tax = $client->getTaxSummary(['storeId' => 's1']);
        $this->assertSame(self::BASE . '/reports/tax-summary?storeId=s1', self::target($http->lastCall()));
        $this->assertSame('273', $tax->rows()[0]['netTaxMinor']);
        $this->assertSame('ITEMIZED', $tax->amountTotals()[0]['scope']);

        $this->expectException(ConfigurationException::class);
        $client->getTaxSummary(['groupBy' => 'DAY']);
    }

    public function testSettlements(): void
    {
        [$client, $http] = $this->make();
        $counts = ['saleCount' => 2, 'saleMinor' => "9000", 'refundCount' => 0, 'refundMinor' => "0", 'voidCount' => 0, 'voidMinor' => "0"];
        $http->pushJson(200, self::page([[
            'commandId' => 'c1', 'settlementRef' => null, 'lineNo' => 1, 'deviceId' => self::DEVICE_ID, 'acquirerCode' => 'BANK',
            'acquirerRefRaw' => '0099', 'acquirerName' => 'Bank', 'deviceName' => null, 'batchNo' => '12',
            'bankTerminalNumber' => null, 'currency' => 'TRY', 'lineStatus' => 'MISMATCH', 'reported' => $counts,
            'system' => array_merge($counts, ['saleMinor' => "9500"]), 'difference' => ['saleCount' => 0, 'saleMinor' => '-500', 'refundCount' => 0, 'refundMinor' => '0', 'voidCount' => 0, 'voidMinor' => '0'],
            'periodFrom' => '2026-10-29T21:00:00.000Z', 'periodTo' => '2026-10-30T21:00:00.000Z', 'deviceClosedAt' => null,
            'recordedAt' => '2026-10-30T21:00:05.000Z',
        ]], true, 'c1:1'));

        $page = $client->listSettlements(['lineStatus' => 'MISMATCH']);

        $line = $page->items()[0];
        $this->assertInstanceOf(PhysicalPosSettlementLine::class, $line);
        $this->assertSame('MISMATCH', $line->lineStatus());
        $this->assertSame('-500', $line->difference()['saleMinor']);
        $this->assertNull($line->bankTerminalNumber());
        $this->assertSame('c1', $line->commandId());
        $this->assertNull($line->settlementRef());
        $this->assertSame('0099', $line->acquirerRefRaw());
        $this->assertSame('Bank', $line->acquirerName());
        $this->assertNull($line->deviceName());
        $this->assertSame('c1:1', $page->nextCursor());
    }

    public function testSettlementStartedOnTheDeviceHasNoCommand(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([[
            'commandId' => null, 'settlementRef' => 'Z-0042', 'lineNo' => 3, 'deviceId' => self::DEVICE_ID, 'acquirerCode' => null,
            'acquirerRefRaw' => 'X9', 'acquirerName' => null, 'deviceName' => 'Till 1', 'currency' => 'TRY', 'lineStatus' => 'UNMAPPED',
        ]]));

        $line = $client->listSettlements()->items()[0];
        $this->assertNull($line->commandId());
        $this->assertSame('Z-0042', $line->settlementRef());
        $this->assertSame('X9', $line->acquirerRefRaw());
        $this->assertNull($line->acquirerName());
        $this->assertSame('Till 1', $line->deviceName());
    }

    public function testReadsAreSignedAndNeverCarryAKey(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]));
        $http->pushJson(200, self::page([]));
        $http->pushJson(200, self::page([]));
        $client->listTransactions();
        $client->listAmbiguousPayments();
        $client->listSettlements();
        foreach ($http->calls as $call) {
            $this->assertSame('OK', $this->verdict($call));
            $this->assertArrayNotHasKey('Idempotency-Key', $call['headers']);
        }
    }

    // ── Webhook ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function webhookPayload(): array
    {
        return [
            'paymentId' => self::PAYMENT_ID, 'rootPaymentId' => self::PAYMENT_ID, 'operation' => 'SALE', 'paymentStatus' => 'APPROVED',
            'statusReason' => 'DEVICE_RESULT_APPROVED', 'amountMinor' => '4500', 'approvedAmountMinor' => '4500', 'currency' => 'TRY',
            'installmentCount' => null, 'deviceId' => self::DEVICE_ID, 'merchantReference' => 'ORD-1',
            'tender' => ['cardMasked' => '454360******1234', 'cardBrand' => 'VISA', 'entryMode' => 'CONTACTLESS'],
            'historySeq' => 4, 'resolvedBy' => null,
        ];
    }

    private const WEBHOOK_SECRET = 'whsec_test';

    /**
     * A delivery signed like the gateway signs it.
     *
     * @param array<string,mixed> $envelope
     * @return array{0:string,1:array<string,string>}
     */
    private static function signedDelivery(array $envelope, string $eventId = 'evt_pos_1'): array
    {
        $timestamp = gmdate('Y-m-d\\TH:i:s\\Z');
        $body = (string) json_encode($envelope);

        return [$body, [
            'x-lunixi-event-id' => $eventId,
            'x-lunixi-event-type' => (string) ($envelope['type'] ?? ''),
            'x-lunixi-signature-timestamp' => $timestamp,
            'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $body, self::WEBHOOK_SECRET),
        ]];
    }

    public function testVerifiedWebhookIsParsedWithTheExistingVerifier(): void
    {
        $eventId = 'evt_pos_1';
        [$body, $headers] = self::signedDelivery([
            'id' => $eventId, 'type' => PhysicalPosEvents::PAYMENT_APPROVED, 'sourceService' => 'physical-pos-service',
            'organizationId' => 'org_1', 'productCode' => 'PHYSICAL_POS', 'environment' => 'LIVE', 'eventVersion' => 1,
            'schemaVersion' => 'v1', 'createdAt' => '2026-10-30T09:00:10.000Z', 'data' => self::webhookPayload(),
        ], $eventId);
        $event = (new WebhookVerifier())->verify($body, $headers, self::WEBHOOK_SECRET);

        $pos = PhysicalPosClient::parseWebhookEvent($body, $headers, self::WEBHOOK_SECRET);

        $this->assertTrue($event->matches(PhysicalPosEvents::ALL_EVENTS));
        $this->assertSame($eventId, $pos->id());
        $this->assertSame(PhysicalPosEvents::PAYMENT_APPROVED, $pos->type());
        $this->assertSame(PhysicalPosWebhookEvent::CATEGORY_PAYMENT, $pos->category());
        $this->assertTrue($pos->isPaymentEvent());
        $this->assertSame('LIVE', $pos->environment());
        $this->assertSame('2026-10-30T09:00:10.000Z', $pos->createdAt());
        $this->assertSame(4, $pos->historySeq());
        $this->assertSame(self::PAYMENT_ID, $pos->paymentId());
        $this->assertSame('4500', $pos->data()['amountMinor'], 'webhook amounts are strings');
        $this->assertArrayNotHasKey('authCode', $pos->data()['tender']);
    }

    public function testDeviceSaleAndItsRefundAreRead(): void
    {
        $sale = array_merge(self::webhookPayload(), [
            'original' => null, 'statusReason' => null, 'merchantReference' => null, 'historySeq' => 1,
            'origin' => 'DEVICE_DIRECT', 'deviceOccurredAt' => '2026-11-01T18:42:10.123Z',
        ]);
        [$body, $headers] = self::signedDelivery(['id' => 'evt_ds', 'type' => PhysicalPosEvents::PAYMENT_DEVICE_SALE, 'data' => $sale], 'evt_ds');
        $event = PhysicalPosClient::parseWebhookEvent($body, $headers, self::WEBHOOK_SECRET);
        $this->assertTrue($event->isDeviceSale());
        $this->assertTrue($event->isPaymentEvent());
        $this->assertNull($event->original());
        $this->assertSame(1, $event->historySeq());
        $this->assertSame('DEVICE_DIRECT', $event->data()['origin']);
        $this->assertSame('2026-11-01T18:42:10.123Z', $event->data()['deviceOccurredAt']);

        $refund = array_merge(self::webhookPayload(), [
            'paymentId' => 'child-1', 'operation' => 'REFUND', 'historySeq' => 3,
            'original' => ['paymentId' => self::PAYMENT_ID, 'origin' => 'DEVICE_DIRECT'],
        ]);
        [$body, $headers] = self::signedDelivery(['id' => 'evt_rf', 'type' => PhysicalPosEvents::PAYMENT_APPROVED, 'data' => $refund], 'evt_rf');
        $child = PhysicalPosClient::parseWebhookEvent($body, $headers, self::WEBHOOK_SECRET);
        $this->assertFalse($child->isDeviceSale());
        $this->assertSame(['paymentId' => self::PAYMENT_ID, 'origin' => 'DEVICE_DIRECT'], $child->original());
    }

    public function testPayloadJsonEnvelopeIsResolved(): void
    {
        [$body, $headers] = self::signedDelivery([
            'eventId' => 'evt_2', 'eventType' => PhysicalPosEvents::FISCAL_DOCUMENT_ACTION_REQUIRED, 'occurredAt' => '2026-10-30T09:00:00.000Z',
            'payloadJson' => ['documentId' => 'd1', 'paymentId' => self::PAYMENT_ID, 'attemptNo' => 1, 'documentType' => 'E_ARCHIVE',
                'fiscalStatus' => 'FAILED', 'actionRequired' => true, 'availableActions' => ['RETRY'], 'alarms' => [], 'historySeq' => 2],
        ], 'evt_2');
        $fromObject = PhysicalPosClient::parseWebhookEvent($body, $headers, self::WEBHOOK_SECRET);
        $this->assertSame('evt_2', $fromObject->id());
        $this->assertSame(PhysicalPosWebhookEvent::CATEGORY_FISCAL_DOCUMENT, $fromObject->category());
        $this->assertSame('2026-10-30T09:00:00.000Z', $fromObject->createdAt());
        $this->assertSame(['RETRY'], $fromObject->data()['availableActions']);

        [$body, $headers] = self::signedDelivery([
            'id' => 'evt_3', 'type' => PhysicalPosEvents::PAYMENT_UNKNOWN, 'data' => [],
            'payloadJson' => (string) json_encode(self::webhookPayload()),
        ], 'evt_3');
        $fromText = PhysicalPosClient::parseWebhookEvent($body, $headers, self::WEBHOOK_SECRET);
        $this->assertSame(4, $fromText->historySeq());
        $this->assertSame(self::DEVICE_ID, $fromText->data()['deviceId']);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badWebhooks(): array
    {
        return [
            'other product' => [['id' => 'e', 'type' => 'payment.captured', 'data' => ['x' => 1]]],
            'no payload' => [['id' => 'e', 'type' => PhysicalPosEvents::PAYMENT_FAILED]],
            'empty payload' => [['id' => 'e', 'type' => PhysicalPosEvents::PAYMENT_FAILED, 'data' => [], 'payloadJson' => '']],
            'bad json text' => [['id' => 'e', 'type' => PhysicalPosEvents::PAYMENT_FAILED, 'payloadJson' => '{not json']],
        ];
    }

    #[DataProvider('badWebhooks')]
    public function testVerifiedWebhookWithoutAPhysicalPosPayloadIsRefused(array $envelope): void
    {
        [$body, $headers] = self::signedDelivery($envelope, 'e');
        $this->expectException(ConfigurationException::class);
        PhysicalPosClient::parseWebhookEvent($body, $headers, self::WEBHOOK_SECRET);
    }

    public function testEventCatalog(): void
    {
        $this->assertCount(9, PhysicalPosEvents::PAYMENT_EVENTS);
        $this->assertSame('physical_pos.payment.device_sale', PhysicalPosEvents::PAYMENT_DEVICE_SALE);
        $this->assertContains(PhysicalPosEvents::PAYMENT_DEVICE_SALE, PhysicalPosEvents::ALL);
        $this->assertCount(4, PhysicalPosEvents::FISCAL_DOCUMENT_EVENTS);
        $this->assertSame(array_merge(PhysicalPosEvents::PAYMENT_EVENTS, PhysicalPosEvents::FISCAL_DOCUMENT_EVENTS), PhysicalPosEvents::ALL);
        foreach (PhysicalPosEvents::ALL as $type) {
            $this->assertStringStartsWith('physical_pos.', $type);
        }
        $this->assertNotContains('physical_pos.payment.created', PhysicalPosEvents::ALL);
        $this->assertNotContains('physical_pos.payment.in_progress', PhysicalPosEvents::ALL);
    }

    // ── Facade ──────────────────────────────────────────────────────────────

    public function testFacadeExposesBothClients(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $lunixi = LunixiClient::create(['baseUrl' => self::BASE_URL, 'keyId' => 'mk_live_sk_kid_1', 'privateKey' => $keys['privateKey']], new FakeHttpClient());

        $this->assertInstanceOf(PhysicalPosClient::class, $lunixi->physicalPos());
        $this->assertInstanceOf(PhysicalPosManagementClient::class, $lunixi->physicalPosManagement());
    }

    public function testNoPublicMethodAcceptsCardDataOrResolve(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(PhysicalPosClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        $this->assertNotContains('resolvePayment', $methods, 'resolving a MANUAL_REVIEW payment is panel only');
        foreach (PhysicalPosClient::PAYMENT_FIELDS as $field) {
            $this->assertFalse(PhysicalPosRequest::isCardDataKey($field), $field);
        }
    }
    public function testCanonicalMoneyRejectsRawNumbersNullAndNestedAmountsBeforeHttp(): void
    {
        [$client, $http] = $this->make();
        foreach ([4500, null, true, '', '01', '1e3', '1.0', ' 1', "1\n", '0', '-1', '9223372036854775808'] as $invalid) {
            $itemRequest = self::saleRequest();
            $itemRequest['items'][0]['unitPriceMinor'] = $invalid;
            $calls = [
                static fn () => $client->createPayment(array_merge(self::saleRequest(), ['amountMinor' => $invalid]), self::KEY),
                static fn () => $client->createPayment($itemRequest, self::KEY),
                static fn () => $client->createReversal(self::PAYMENT_ID, ['operation' => 'REFUND', 'currency' => 'TRY', 'amountMinor' => $invalid], self::KEY),
                static fn () => $client->createReversal(self::PAYMENT_ID, ['operation' => 'REFUND', 'currency' => 'TRY', 'items' => [['lineNo' => 1, 'quantityMilli' => 1000, 'amountMinor' => $invalid]]], self::KEY),
                static fn () => $client->listTransactions(['amountFromMinor' => $invalid]),
            ];
            foreach ($calls as $call) {
                try { $call(); $this->fail('Expected strict money rejection'); }
                catch (ConfigurationException $error) { $this->assertSame(0, $http->callCount()); }
            }
        }
    }

    public function testLargeMoneyStringsAndCountsSurviveTransportExactly(): void
    {
        [$client, $http] = $this->make();
        $exact = '9007199254740993';
        $http->pushJson(202, self::envelope(['payment' => self::paymentJson(['amountMinor' => $exact]), 'replayed' => false], 202));
        $request = self::saleRequest();
        $request['amountMinor'] = $exact;
        $request['currency'] = 'KWD';
        $request['items'] = [['lineNo' => 1, 'name' => 'Exact', 'quantityMilli' => 1000, 'unitCode' => 'C62', 'unitPriceMinor' => $exact, 'taxGroupCode' => 'KDV0', 'lineTotalMinor' => $exact]];
        $result = $client->createPayment($request, self::KEY);
        $this->assertSame($exact, $result->payment()->amountMinor());
        $body = self::bodyOf($http->lastCall());
        $this->assertSame($exact, $body['items'][0]['unitPriceMinor']);
        $this->assertSame(1000, $body['items'][0]['quantityMilli']);
    }

    public function testNumericMoneyInAcceptedResponseIsUnknown(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(202, self::envelope(['payment' => self::paymentJson(['amountMinor' => 4500]), 'replayed' => false], 202));
        try { $client->createPayment(self::saleRequest(), self::KEY); $this->fail('Expected unknown outcome'); }
        catch (PhysicalPosOutcomeUnknownException $error) { $this->assertSame(1, $http->callCount()); }
    }

}
