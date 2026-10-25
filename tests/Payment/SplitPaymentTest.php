<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Payment\CheckoutFormErrorCode;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Payment\PaymentIntent;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\PaymentMethodInfo;
use Lunixi\Sdk\Payment\PaymentSplitRef;
use Lunixi\Sdk\Payment\SplitBehavior;
use Lunixi\Sdk\Payment\SplitPayErrorCode;
use Lunixi\Sdk\Payment\SplitPaymentEvents;
use Lunixi\Sdk\Payment\SplitPaymentPendingStep;
use Lunixi\Sdk\Payment\SplitPaymentRequest;
use Lunixi\Sdk\Payment\SplitPaymentResult;
use Lunixi\Sdk\Payment\SplitPaymentStatus;
use Lunixi\Sdk\Payment\SplitRefundResult;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Split payments: POST /payments/split (create and continue), GET /payments/split[/:id],
 * POST /payments/split/:id/refund, GET /payments?includeLegs and the split fields of
 * GET /payments/methods. Same rules as the Node SDK (node/test/split-payment.test.js).
 */
final class SplitPaymentTest extends TestCase
{
    private const SPLIT_ID = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
    private const LEG_PAYMENT_ID = '9a7d5e3c-1b2a-4f6e-8d0c-7b6a5f4e3d2c';

    /** @return array{0:ApiClient,1:FakeHttpClient} */
    private function api(): array
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

        return [new ApiClient($config, $signer, $tokens, $http), $http];
    }

    private function cardAndMobileBilling(): SplitPaymentRequest
    {
        return (new SplitPaymentRequest(100000, 'try', 'cuk_abc123'))
            ->withOrderId('order-2026-001')
            ->withCallbackUrl('https://merchant.example/split-return')
            ->addCardLeg('ct_vault_1', 60000, true)
            ->addMethodLeg(PaymentMethod::MOBILE_BILLING, 'PAYCELL_MOBILE_BILLING', 40000, ['gsmNumber' => '+905321234567']);
    }

    /** @return array<string,mixed> */
    private static function splitData(string $status, array $extra = []): array
    {
        return $extra + [
            'splitPaymentId' => self::SPLIT_ID,
            'status' => $status,
            'duplicate' => false,
            'needsReconciliation' => false,
            'lockContended' => false,
            'legs' => [],
            'steps' => [],
            'pendingStep' => null,
            'expiresAt' => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function body(FakeHttpClient $http): array
    {
        return json_decode((string) $http->lastCall()['body'], true);
    }

    // --- SplitPaymentRequest -----------------------------------------------------------

    public function testRequestBuildsACardLegAndAMethodLegWithTheDirectPaymentBlock(): void
    {
        $this->assertSame([
            'totalAmount' => 100000,
            'currency' => 'TRY',
            'ownerRef' => 'cuk_abc123',
            'orderId' => 'order-2026-001',
            'callbackUrl' => 'https://merchant.example/split-return',
            'legs' => [
                ['cardToken' => 'ct_vault_1', 'amount' => 60000, 'force3D' => true],
                [
                    'paymentMethod' => 'MOBILE_BILLING',
                    'amount' => 40000,
                    'mobileBilling' => ['providerCode' => 'PAYCELL_MOBILE_BILLING', 'gsmNumber' => '+905321234567'],
                ],
            ],
        ], $this->cardAndMobileBilling()->toArray());
    }

    public function testEveryKeyTheBuilderEmitsIsAGatewayDtoField(): void
    {
        $body = (new SplitPaymentRequest(30000, 'TRY', 'cuk_1'))
            ->addCardLeg('ct_1', 10000, false, 3, 'iq_1')
            ->addMethodLeg(PaymentMethod::WALLET, 'PAYCELL_WALLET', 10000, ['gsmNumber' => '+905321234567'])
            ->addMethodLeg(PaymentMethod::PROMISSORY_NOTE, 'EXAMPLE_PROVIDER', 10000, ['noteCount' => 3, 'debtorType' => 'INDIVIDUAL'])
            ->toArray();

        $this->assertSame([], array_values(array_diff(array_keys($body), SplitPaymentRequest::FIELDS)));
        foreach ($body['legs'] as $leg) {
            $this->assertSame([], array_values(array_diff(array_keys($leg), SplitPaymentRequest::LEG_FIELDS)));
        }
        $this->assertSame('3', $body['legs'][2]['promissoryNote']['noteCount']);
        $this->assertSame(['cardToken' => 'ct_1', 'amount' => 10000, 'installment' => 3, 'installmentQuoteToken' => 'iq_1'], $body['legs'][0]);
        foreach (PaymentMethod::METHOD_BLOCK_FIELDS as $block) {
            $this->assertContains($block, SplitPaymentRequest::LEG_FIELDS);
        }
    }

    public function testLegsMustAddUpToTheTotal(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('add up to 90000 but totalAmount is 100000');
        (new SplitPaymentRequest(100000, 'TRY', 'cuk_1'))->addCardLeg('ct_1', 60000)->addCardLeg('ct_2', 30000)->toArray();
    }

    public function testLegRulesAreCheckedBeforeSending(): void
    {
        $base = static fn (): SplitPaymentRequest => new SplitPaymentRequest(20000, 'TRY', 'cuk_1');
        $refused = [
            'card through addMethodLeg' => static fn () => $base()->addMethodLeg(PaymentMethod::CARD, 'X', 10000),
            'no block for the method' => static fn () => $base()->addMethodLeg(PaymentMethod::BNPL, 'X', 10000),
            'providerCode in the fields' => static fn () => $base()->addMethodLeg(PaymentMethod::WALLET, 'A', 10000, ['providerCode' => 'B']),
            'invalid providerCode' => static fn () => $base()->addMethodLeg(PaymentMethod::WALLET, 'PAYCELL WALLET', 10000),
            'duplicate provider' => static fn () => $base()->addMethodLeg(PaymentMethod::WALLET, 'A', 10000)->addMethodLeg(PaymentMethod::WALLET, 'A', 10000),
            'duplicate card' => static fn () => $base()->addCardLeg('ct_1', 10000)->addCardLeg('ct_1', 10000),
            'promissory schedule' => static fn () => $base()->addMethodLeg(PaymentMethod::PROMISSORY_NOTE, 'A', 10000, ['schedule' => [['amount' => 10000, 'dueDate' => '2030-01-15']]]),
            'promissory without noteCount' => static fn () => $base()->addMethodLeg(PaymentMethod::PROMISSORY_NOTE, 'A', 10000),
            'installment without quote' => static fn () => $base()->addCardLeg('ct_1', 10000, false, 2),
            'installment above 36' => static fn () => $base()->addCardLeg('ct_1', 10000, false, 37, 'iq'),
            'zero amount' => static fn () => $base()->addCardLeg('ct_1', 0),
            '3DS without callbackUrl' => static fn () => (new SplitPaymentRequest(10000, 'TRY', 'cuk_1'))->addCardLeg('ct_1', 10000, true)->toArray(),
            'no legs' => static fn () => (new SplitPaymentRequest(10000, 'TRY', 'cuk_1'))->toArray(),
            'empty ownerRef' => static fn () => new SplitPaymentRequest(10000, 'TRY', ' '),
            'plain http callback missing scheme' => static fn () => $base()->withCallbackUrl('merchant.example/return'),
        ];
        foreach ($refused as $label => $build) {
            try {
                $build();
                $this->fail($label . ' must be refused');
            } catch (ConfigurationException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $six = new SplitPaymentRequest(6000, 'TRY', 'cuk_1');
        for ($i = 0; $i < 6; $i++) {
            $six->addCardLeg('ct_' . $i, 1000);
        }
        $this->expectException(ConfigurationException::class);
        $six->toArray();
    }

    // --- createSplitPayment ------------------------------------------------------------

    public function testCreateIsASignedKeyedPost(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'COMPLETED', 'data' => self::splitData('COMPLETED')]);

        $result = (new PaymentClient($api))->createSplitPayment($this->cardAndMobileBilling(), 'split:order-2026-001');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/split', $call['url']);
        $this->assertSame('split:order-2026-001', $call['headers']['Idempotency-Key']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertArrayHasKey('Digest', $call['headers']);
        $this->assertSame($this->cardAndMobileBilling()->toArray(), self::body($http));
        $this->assertTrue($result->isCompleted());
        $this->assertSame(self::SPLIT_ID, $result->splitPaymentId());
    }

    public function testCreateRefusesToSendWithoutAValidIdempotencyKey(): void
    {
        [$api, $http] = $this->api();
        foreach ([null, '', '   ', str_repeat('k', 201)] as $key) {
            try {
                (new PaymentClient($api))->createSplitPayment($this->cardAndMobileBilling(), $key);
                $this->fail('the Idempotency-Key must be refused');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('Idempotency-Key', $e->getMessage());
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testARunningSplitPaymentIsReturnedNotThrown(): void
    {
        $pendingStep = [
            'legId' => 'leg-2',
            'sequence' => 2,
            'step' => SplitPaymentPendingStep::ACTION,
            'paymentMethod' => 'MOBILE_BILLING',
            'providerCode' => 'PAYCELL_MOBILE_BILLING',
            'amount' => 40000,
            'paymentId' => self::LEG_PAYMENT_ID,
            'actionId' => 'act_1',
            'redirectPath' => null,
            'action' => ['id' => 'act_1', 'type' => 'OTP', 'status' => 'OPEN', 'expiresAt' => '2026-10-18T13:03:00.000Z'],
        ];
        $steps = [
            ['legId' => 'leg-1', 'sequence' => 1, 'kind' => 'CARD', 'paymentMethod' => 'CARD', 'providerCode' => null, 'splitBehavior' => 'HOLD_CAPTURE', 'amount' => 60000, 'status' => 'AUTHORIZED', 'paymentId' => null, 'replacesLegId' => null, 'current' => false],
            ['legId' => 'leg-2', 'sequence' => 2, 'kind' => 'METHOD', 'paymentMethod' => 'MOBILE_BILLING', 'providerCode' => 'PAYCELL_MOBILE_BILLING', 'splitBehavior' => 'CAPTURE_IRREVERSIBLE', 'amount' => 40000, 'status' => 'AWAITING_ACTION', 'paymentId' => self::LEG_PAYMENT_ID, 'replacesLegId' => null, 'current' => true],
        ];
        foreach (SplitPaymentStatus::IN_PROGRESS as $status) {
            [$api, $http] = $this->api();
            $http->pushJson(400, [
                'status' => 'failure',
                'code' => $status,
                'statusCode' => 400,
                'data' => self::splitData($status, ['pendingStep' => $pendingStep, 'steps' => $steps, 'expiresAt' => '2026-10-18T13:20:00.000Z']),
            ]);

            $result = (new PaymentClient($api))->createSplitPayment($this->cardAndMobileBilling(), 'split-k');

            $this->assertSame($status, $result->status());
            $this->assertTrue($result->isInProgress());
            $pending = $result->pendingStep();
            $this->assertNotNull($pending);
            $this->assertSame(self::LEG_PAYMENT_ID, $pending->paymentId());
            $this->assertSame('act_1', $pending->actionId());
            $this->assertSame('OTP', $pending->action() !== null ? $pending->action()->type() : null);
            $this->assertCount(2, $result->steps());
            $this->assertTrue($result->steps()[1]->isCurrent());
            $this->assertSame(SplitBehavior::CAPTURE_IRREVERSIBLE, $result->steps()[1]->splitBehavior());
            $this->assertNull($result->steps()[0]->providerCode());
            $this->assertSame('2026-10-18T13:20:00.000Z', $result->expiresAt());
        }
    }

    public function testAFailedSplitPaymentAndARuleViolationStillThrow(): void
    {
        foreach ([SplitPaymentStatus::VOIDED, SplitPaymentStatus::FAILED, SplitPaymentStatus::COMPENSATING, SplitPaymentStatus::RECONCILIATION_REQUIRED] as $status) {
            [$api, $http] = $this->api();
            $http->pushJson(400, ['status' => 'failure', 'code' => $status, 'statusCode' => 400, 'data' => self::splitData($status)]);
            try {
                (new PaymentClient($api))->createSplitPayment($this->cardAndMobileBilling(), 'split-k');
                $this->fail($status . ' must throw');
            } catch (ApiException $e) {
                $this->assertSame($status, $e->getErrorCode());
                $this->assertSame(self::SPLIT_ID, (new SplitPaymentResult($e->getResponseBody() ?? []))->splitPaymentId());
            }
        }

        [$api, $http] = $this->api();
        $http->pushJson(422, ['status' => 'failure', 'code' => SplitPayErrorCode::DUPLICATE_PROVIDER, 'statusCode' => 422, 'data' => null]);
        try {
            (new PaymentClient($api))->createSplitPayment($this->cardAndMobileBilling(), 'split-k');
            $this->fail('a 422 must throw');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('split_pay.validation.duplicate_provider', $e->getErrorCode());
        }

        [$api, $http] = $this->api();
        $http->pushJson(400, ['status' => 'failure', 'code' => 'AWAITING_ACTION', 'data' => []]);
        $this->expectException(ApiException::class);
        (new PaymentClient($api))->createSplitPayment($this->cardAndMobileBilling(), 'split-k');
    }

    // --- read and refund -----------------------------------------------------------------

    public function testGetAndListAreBearerGetsAndListFiltersRepeat(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => [
            'id' => self::SPLIT_ID,
            'status' => 'COMPLETED',
            'currency' => 'TRY',
            'legs' => [[
                'id' => 'leg-1', 'sequence' => 1, 'legAmount' => 60000, 'installmentCount' => 3, 'chargeAmount' => 63000,
                'capturedAmount' => 63000, 'refundedAmount' => 0, 'legStatus' => 'CAPTURED', 'paymentMethod' => 'CARD',
                'refundState' => null, 'dispute' => null,
            ]],
            'refund' => ['refundableAmount' => 63000, 'nonRefundableAmount' => 40000, 'refundedAmount' => 0],
            'dispute' => ['hasDispute' => false, 'disputedAmount' => 0, 'legCount' => 0],
        ]]);
        $detail = (new PaymentClient($api))->getSplitPayment(self::SPLIT_ID);
        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/split/' . self::SPLIT_ID, $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers']);
        $this->assertSame(63000, $detail->refundableAmount());
        $this->assertSame(40000, $detail->nonRefundableAmount());
        $this->assertSame(63000, $detail->legs()[0]->chargeAmount());
        $this->assertSame(3, $detail->legs()[0]->installmentCount());

        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => [
            'items' => [['id' => self::SPLIT_ID, 'status' => 'COMPLETED']],
            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cur_2'],
            'totalCount' => null,
        ]]);
        $list = (new PaymentClient($api))->listSplitPayments(['status' => ['COMPLETED', 'VOIDED'], 'currency' => 'TRY', 'limit' => 10, 'after' => 'cur/1']);
        $this->assertSame(
            'https://gw.example.com/api/v1/payments/split?status=COMPLETED&status=VOIDED&currency=TRY&limit=10&after=cur%2F1',
            $http->lastCall()['url']
        );
        $this->assertCount(1, $list->items());
        $this->assertTrue($list->hasNextPage());
        $this->assertSame('cur_2', $list->endCursor());

        foreach ([['status' => 'DONE'], ['limit' => 0], ['limit' => 101], ['currency' => 'try'], ['page' => 2]] as $filters) {
            try {
                (new PaymentClient($api))->listSplitPayments($filters);
                $this->fail(json_encode($filters) . ' must be refused');
            } catch (ConfigurationException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRefundIsASignedKeyedPostWithAmountAndReasonOnly(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SPLIT_REFUND_COMPLETED', 'data' => [
            'refundRequestId' => 'rr_1', 'splitPaymentId' => self::SPLIT_ID, 'status' => 'COMPLETED',
            'requestedAmount' => 1500, 'refundedAmount' => 1500, 'refundableAmount' => 61500, 'nonRefundableAmount' => 40000,
            'duplicate' => false, 'legs' => [['legId' => 'leg-1', 'status' => 'SUCCEEDED', 'amount' => 1500]],
        ]]);

        $result = (new PaymentClient($api))->refundSplitPayment(self::SPLIT_ID, 1500, 'Customer return', 'refund:order-2026-001:1');

        $call = $http->lastCall();
        $this->assertSame('https://gw.example.com/api/v1/payments/split/' . self::SPLIT_ID . '/refund', $call['url']);
        $this->assertSame(['amount' => 1500, 'reason' => 'Customer return'], self::body($http));
        $this->assertSame('refund:order-2026-001:1', $call['headers']['Idempotency-Key']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertTrue($result->isCompleted());
        $this->assertSame(61500, $result->refundableAmount());
        $this->assertCount(1, $result->legs());

        foreach ([[1500, null], [0, 'k'], [100, str_repeat('k', 201)]] as [$amount, $key]) {
            try {
                (new PaymentClient($api))->refundSplitPayment(self::SPLIT_ID, $amount, null, $key);
                $this->fail('must be refused');
            } catch (ConfigurationException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnIncompleteRefundThrowsWithThePerLegResult(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(400, ['status' => 'failure', 'code' => SplitPayErrorCode::REFUND_INCOMPLETE, 'statusCode' => 400, 'data' => [
            'status' => 'PARTIAL',
            'legs' => [['legId' => 'l1', 'status' => 'SUCCEEDED'], ['legId' => 'l2', 'status' => 'FAILED', 'errorCode' => 'payment.refund.provider_error']],
        ]]);
        try {
            (new PaymentClient($api))->refundSplitPayment(self::SPLIT_ID, 1500, null, 'k');
            $this->fail('an incomplete refund must throw');
        } catch (ApiException $e) {
            $this->assertSame('split_pay.refund.incomplete', $e->getErrorCode());
            $this->assertCount(2, (new SplitRefundResult($e->getResponseBody() ?? []))->legs());
        }
    }

    public function testListSendsIncludeLegsAndRowsCarryTheSplitMark(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['data' => [
            ['id' => 'p1', 'split' => ['splitPaymentId' => self::SPLIT_ID, 'role' => 'PARENT', 'parentPaymentId' => null, 'legSequence' => null]],
            ['id' => 'p2', 'split' => ['splitPaymentId' => self::SPLIT_ID, 'role' => 'LEG', 'parentPaymentId' => 'p1', 'legSequence' => 2]],
            ['id' => 'p3', 'split' => null],
        ]]);

        $list = (new PaymentClient($api))->list(['limit' => 10, 'includeLegs' => true]);

        $this->assertSame('https://gw.example.com/api/v1/payments?limit=10&includeLegs=true', $http->lastCall()['url']);
        [$parent, $leg, $plain] = $list->items();
        $this->assertTrue($parent->split() !== null && $parent->split()->isParent());
        $this->assertTrue($leg->split() !== null && $leg->split()->isLeg());
        $this->assertSame(2, $leg->split()->legSequence());
        $this->assertSame('p1', $leg->split()->parentPaymentId());
        $this->assertNull($plain->split());
        $this->assertNull((new PaymentIntent(['split' => ['splitPaymentId' => '', 'role' => '']]))->split());

        $this->expectException(ConfigurationException::class);
        (new PaymentClient($api))->list(['includeLegs' => 'yes']);
    }

    public function testMethodRowsCarryTheSplitFields(): void
    {
        $method = new PaymentMethodInfo([
            'paymentMethod' => 'MOBILE_BILLING',
            'splitEligible' => true,
            'splitBehavior' => 'CAPTURE_IRREVERSIBLE',
            'providers' => [
                ['providerCode' => 'PAYCELL_MOBILE_BILLING', 'splitEligible' => true, 'splitBehavior' => 'CAPTURE_IRREVERSIBLE'],
                ['providerCode' => 'OTHER', 'splitEligible' => false, 'splitBehavior' => null],
            ],
        ]);
        $this->assertTrue($method->splitEligible());
        $this->assertSame(SplitBehavior::CAPTURE_IRREVERSIBLE, $method->splitBehavior());
        $this->assertTrue($method->providers()[0]->splitEligible());
        $this->assertFalse($method->providers()[1]->splitEligible());
        $this->assertNull($method->providers()[1]->splitBehavior());
        $this->assertFalse((new PaymentMethodInfo([]))->splitEligible());
    }

    public function testValueSetsMatchTheNodeSdk(): void
    {
        $this->assertSame(
            ['APPROVE_THEN_DISBURSE', 'HOLD_CAPTURE', 'CAPTURE_REVERSIBLE', 'CAPTURE_IRREVERSIBLE'],
            array_values((new \ReflectionClass(SplitBehavior::class))->getConstants())
        );
        $this->assertCount(13, SplitPaymentStatus::ALL);
        $this->assertSame([], array_values(array_diff(SplitPaymentStatus::IN_PROGRESS, SplitPaymentStatus::ALL)));
        foreach ((new \ReflectionClass(SplitPayErrorCode::class))->getConstants() as $code) {
            $this->assertMatchesRegularExpression('/^split_pay\.[a-z_]+\.[a-z_]+$/', $code);
        }
        $this->assertSame([
            'PROVIDER_DISABLED_IN_FORM' => 'payment.provider.disabled_in_form',
            'METHOD_SELECTION_ATTEMPTS_EXHAUSTED' => 'payment.method.selection_attempts_exhausted',
            'ORDER_LOCKED' => 'payment.checkout.order_locked',
        ], (new \ReflectionClass(CheckoutFormErrorCode::class))->getConstants());
        $this->assertSame('payment.split.leg_events', SplitPaymentEvents::LEG_EVENTS_SUBSCRIPTION);
        $this->assertSame('PARENT', PaymentSplitRef::ROLE_PARENT);
        $this->assertSame(['totalAmount', 'currency', 'ownerRef', 'legs', 'orderId', 'ownerScope', 'callbackUrl'], SplitPaymentRequest::FIELDS);
    }
}
