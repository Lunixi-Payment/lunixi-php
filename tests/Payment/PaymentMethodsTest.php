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
use Lunixi\Sdk\Payment\Address;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\Buyer;
use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\MethodInputField;
use Lunixi\Sdk\Payment\MethodPaymentResult;
use Lunixi\Sdk\Payment\PaymentActionConfirmRequest;
use Lunixi\Sdk\Payment\PaymentActionType;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Payment\PaymentIntent;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\PaymentMethodInfo;
use Lunixi\Sdk\Payment\ProviderSelection;
use Lunixi\Sdk\Payment\ProviderSelectionMode;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Payment-method endpoints: POST /payments/direct/payment, the action endpoint
 * (confirm / resend) and GET /payments/methods. Bodies follow the gateway's
 * DirectMethodPaymentDto / ConfirmPaymentActionDto (forbidNonWhitelisted).
 */
final class PaymentMethodsTest extends TestCase
{
    private const PAYMENT_ID = '3f1c2a9e-5b7d-4c1e-9a2b-6d8e0f1a2b3c';

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

    private function mobileBilling(): DirectMethodPaymentRequest
    {
        return (new DirectMethodPaymentRequest(5000, 'try', 'ORD-MB-1'))
            ->withMethodFields(PaymentMethod::MOBILE_BILLING, null, ['gsmNumber' => '+905321234567'])
            ->withCallbackUrl('https://merchant.example.com/payment/callback');
    }

    private function card(): CardDetails
    {
        return new CardDetails([
            'cardHolderName' => 'Ada Yilmaz', 'cardNumber' => '5400000000000004',
            'expireMonth' => '12', 'expireYear' => '28', 'cvcNumber' => '123',
        ]);
    }

    private static function buyer(): Buyer
    {
        return new Buyer([
            'name' => 'Ada', 'surname' => 'L', 'identityNumber' => '11111111111',
            'email' => 'a@b.co', 'gsmNumber' => '+905321234567', 'city' => 'Istanbul',
            'country' => 'TR', 'zipCode' => '34000', 'ip' => '1.2.3.4',
        ]);
    }

    private static function address(): Address
    {
        return new Address([
            'address' => 'Street 1', 'zipCode' => '34000', 'contactName' => 'Ada',
            'city' => 'Istanbul', 'country' => 'TR',
        ]);
    }

    private static function basketItem(int $price): BasketItem
    {
        return new BasketItem(['id' => 'i1', 'price' => $price, 'name' => 'Widget', 'category1' => 'Cat', 'itemType' => BasketItem::TYPE_VIRTUAL]);
    }

    /** @return array<string,mixed> */
    private static function otpEnvelope(string $actionId): array
    {
        return [
            'status' => 'partial',
            'code' => 'payment.action.required',
            'statusCode' => 202,
            'data' => [
                'paymentId' => self::PAYMENT_ID,
                'orderId' => 'ORD-MB-1',
                'status' => 'INITIATED',
                'paymentMethod' => 'MOBILE_BILLING',
                'providerCode' => 'PAYCELL_MOBILE_BILLING',
                'action' => [
                    'id' => $actionId,
                    'type' => 'OTP',
                    'expiresAt' => '2026-10-11T10:05:00.000Z',
                    'otp' => ['maskedTarget' => '+90532***4567'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function body(FakeHttpClient $http): array
    {
        return json_decode((string) $http->lastCall()['body'], true);
    }

    // --- DirectMethodPaymentRequest ----------------------------------------------------

    public function testWithMethodFieldsWritesTheMethodAndOnlyItsBlock(): void
    {
        $this->assertSame([
            'paymentMethod' => 'MOBILE_BILLING',
            'amount' => 5000,
            'currency' => 'TRY',
            'orderId' => 'ORD-MB-1',
            'callbackUrl' => 'https://merchant.example.com/payment/callback',
            'mobileBilling' => ['gsmNumber' => '+905321234567'],
        ], $this->mobileBilling()->toArray());
    }

    public function testProviderCodeGoesIntoTheBlockAndAmountIsTheOnlyAmountField(): void
    {
        $body = (new DirectMethodPaymentRequest(12500, 'TRY', 'ORD-W-1'))
            ->withMethodFields(PaymentMethod::WALLET, 'PAYCELL_WALLET', ['gsmNumber' => '+905321234567'])
            ->toArray();

        $this->assertSame(12500, $body['amount']);
        $this->assertArrayNotHasKey('price', $body);
        $this->assertArrayNotHasKey('paidPrice', $body);
        $this->assertSame(['providerCode' => 'PAYCELL_WALLET', 'gsmNumber' => '+905321234567'], $body['wallet']);
        $this->assertArrayNotHasKey('mobileBilling', $body);
    }

    public function testAnEmptyBlockIsSentAsAJsonObject(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(202, self::otpEnvelope('act_1'));
        $request = (new DirectMethodPaymentRequest(5000, 'TRY', 'ORD-MB-2'))
            ->withMethodFields(PaymentMethod::MOBILE_BILLING, null)
            ->withBuyer(self::buyer());

        (new PaymentClient($api))->directMethodPayment($request, 'idem-empty');

        $this->assertStringContainsString('"mobileBilling":{}', (string) $http->lastCall()['body']);
    }

    public function testWithPreauthWritesTheTypedFieldOnly(): void
    {
        $body = $this->mobileBilling()->withMetadata(['crm' => 'A-1'])->withPreauth(true)->toArray();

        $this->assertTrue($body['preauth']);
        $this->assertSame(['crm' => 'A-1'], $body['metadata']);
        $this->assertFalse($this->mobileBilling()->withPreauth(false)->toArray()['preauth']);
        $this->assertArrayNotHasKey('preauth', $this->mobileBilling()->toArray());
    }

    public function testWithCardPaysByCardWithTheDirectCardFields(): void
    {
        $body = (new DirectMethodPaymentRequest(15000, 'TRY', 'ORD-C-1'))
            ->withCard($this->card())
            ->withForce3D(true)
            ->withInstallment(3, 'iq_1')
            ->toArray();

        $this->assertSame('CARD', $body['paymentMethod']);
        $this->assertSame('5400000000000004', $body['card']['cardNumber']);
        $this->assertTrue($body['force3D']);
        $this->assertSame(3, $body['installment']);
        $this->assertSame('iq_1', $body['installmentQuoteToken']);
    }

    public function testCardIsChosenWithWithCard(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('withCard');

        (new DirectMethodPaymentRequest(5000, 'TRY', 'O'))->withMethodFields(PaymentMethod::CARD, null, []);
    }

    public function testCardOnlyFieldsAreRefusedWithAnotherMethod(): void
    {
        foreach ([
            $this->mobileBilling()->withForce3D(true),
            $this->mobileBilling()->withInstallment(2),
        ] as $request) {
            try {
                $request->toArray();
                $this->fail('card-only fields must be refused');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('card payment', $e->getMessage());
            }
        }
    }

    public function testARequestWithoutAMethodCannotBeSerialized(): void
    {
        $this->expectException(ConfigurationException::class);

        (new DirectMethodPaymentRequest(5000, 'TRY', 'O'))->toArray();
    }

    /** @return array<string,array{0:string}> */
    public static function methodsWithoutABlock(): array
    {
        return ['bnpl' => ['BNPL'], 'qr' => ['QR'], 'qr code' => ['QR_CODE'], 'transfer' => ['BANK_TRANSFER']];
    }

    #[DataProvider('methodsWithoutABlock')]
    public function testAMethodTheGatewayHasNoBlockForIsRefused(string $method): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be paid through direct/payment yet');

        (new DirectMethodPaymentRequest(5000, 'TRY', 'O'))->withMethodFields($method, null, []);
    }

    /** @return array<string,array{0:array<mixed>}> */
    public static function malformedFields(): array
    {
        return [
            'providerCode in fields' => [['providerCode' => 'X']],
            'dashed key' => [['gsm-number' => '+90']],
            'list' => [['gsmNumber']],
            'nested too deep' => [['address' => ['line' => ['deep' => true]]]],
            'object too large' => [['address' => array_combine(array_map(static fn (int $i): string => 'f' . $i, range(0, 16)), array_fill(0, 17, 'v'))]],
            'group too long' => [['guarantors' => array_fill(0, 11, ['name' => 'a'])]],
            'group of scalars' => [['guarantors' => ['a']]],
            'list inside a group item' => [['guarantors' => [['address' => [['nested' => true]]]]]],
            'dashed key in a group item' => [['guarantors' => [['tc-no' => '1']]]],
            'reserved key' => [['constructor' => 'x']],
            'null value' => [['gsmNumber' => null]],
            'too long' => [['gsmNumber' => str_repeat('x', 1025)]],
            'not finite' => [['amount' => INF]],
            'too many' => [array_combine(array_map(static fn (int $i): string => 'f' . $i, range(0, 32)), array_fill(0, 33, 'v'))],
        ];
    }

    /** @param array<mixed> $fields */
    #[DataProvider('malformedFields')]
    public function testMethodFieldsAreCheckedForTheGatewayStructure(array $fields): void
    {
        $this->expectException(ConfigurationException::class);

        (new DirectMethodPaymentRequest(5000, 'TRY', 'O'))->withMethodFields(PaymentMethod::MOBILE_BILLING, null, $fields);
    }

    public function testAddressObjectsAndGroupListsPassAsTheGatewayAcceptsThem(): void
    {
        $fields = [
            'address' => ['provinceCode' => '06', 'districtCode' => '100', 'neighborhoodCode' => '200', 'line' => 'Ornek Sokak 1'],
            'guarantors' => [['name' => 'Test', 'address' => ['provinceCode' => '34']]],
        ];
        $body = (new DirectMethodPaymentRequest(5000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::MOBILE_BILLING, null, $fields)
            ->toArray();

        $this->assertSame($fields, $body['mobileBilling']);
        $this->assertSame(
            '{"address":{"provinceCode":"06","districtCode":"100","neighborhoodCode":"200","line":"Ornek Sokak 1"},"guarantors":[{"name":"Test","address":{"provinceCode":"34"}}]}',
            json_encode($body['mobileBilling'])
        );
    }

    public function testInvalidProviderCodeAndAmountsAreRefused(): void
    {
        foreach (['', 'PAYCELL WALLET', str_repeat('a', 65)] as $providerCode) {
            try {
                (new DirectMethodPaymentRequest(5000, 'TRY', 'O'))->withMethodFields(PaymentMethod::WALLET, $providerCode, []);
                $this->fail('invalid providerCode accepted: ' . $providerCode);
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('providerCode', $e->getMessage());
            }
        }
        foreach ([0, -1] as $amount) {
            try {
                new DirectMethodPaymentRequest($amount, 'TRY', 'O');
                $this->fail('non-positive amount accepted');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString("'amount'", $e->getMessage());
            }
        }
    }

    public function testEveryKeyTheBuilderEmitsIsADtoField(): void
    {
        $full = (new DirectMethodPaymentRequest(15000, 'TRY', 'ORD-ALL'))
            ->withCard($this->card())
            ->withInstallment(2, 'iq')
            ->withForce3D(true)
            ->withPreauth(true)
            ->withCallbackUrl('https://merchant.example.com/cb')
            ->withLocale('tr')
            ->withDescription('d')
            ->withCustomerId('c')
            ->withPaymentChannel('WEB')
            ->withPaymentGroup('PRODUCT')
            ->withBuyer(self::buyer())
            ->withBillingAddress(self::address())
            ->withShippingAddress(self::address())
            ->withBasketItems([self::basketItem(15000)])
            ->withMetadata(['k' => 'v'])
            ->toArray();
        $wallet = (new DirectMethodPaymentRequest(1, 'TRY', 'O'))->withMethodFields(PaymentMethod::WALLET, 'P', [])->toArray();

        foreach (array_merge(array_keys($full), array_keys($wallet), array_keys($this->mobileBilling()->toArray())) as $key) {
            $this->assertContains($key, PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS, $key);
        }
    }

    public function testBlockNameIsTheCamelCaseOfTheCode(): void
    {
        $this->assertSame('mobileBilling', PaymentMethod::blockName('MOBILE_BILLING'));
        $this->assertSame('cashOnDelivery', PaymentMethod::blockName('CASH_ON_DELIVERY'));
        $this->assertSame('qrCode', PaymentMethod::blockName('QR'));
        $this->assertSame('card', PaymentMethod::blockName('CARD'));
    }

    // --- PaymentClient::directMethodPayment --------------------------------------------

    public function testDirectMethodPaymentIsSignedKeyedAndTyped(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(202, self::otpEnvelope('act_1'));

        $result = (new PaymentClient($api))->directMethodPayment($this->mobileBilling(), 'pay:ORD-MB-1');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/direct/payment', $call['url']);
        $this->assertSame('pay:ORD-MB-1', $call['headers']['Idempotency-Key']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertArrayHasKey('Digest', $call['headers']);
        $this->assertSame($this->mobileBilling()->toArray(), self::body($http));

        $this->assertSame(self::PAYMENT_ID, $result->paymentId());
        $this->assertSame('INITIATED', $result->status());
        $this->assertSame('PAYCELL_MOBILE_BILLING', $result->providerCode());
        $action = $result->action();
        $this->assertNotNull($action);
        $this->assertSame(PaymentActionType::OTP, $action->type());
        $this->assertSame('act_1', $action->id());
        $this->assertSame('+90532***4567', $action->maskedTarget());
    }

    public function testDirectMethodPaymentRefusesToSendWithoutAnIdempotencyKey(): void
    {
        [$api, $http] = $this->api();

        foreach ([null, '', '   '] as $key) {
            try {
                (new PaymentClient($api))->directMethodPayment($this->mobileBilling(), $key);
                $this->fail('a missing Idempotency-Key must be refused');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('Idempotency-Key', $e->getMessage());
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testADeclineThrowsAndItsBodyReadsAsAResult(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(400, [
            'status' => 'failure',
            'code' => 'payment.declined',
            'message' => 'Payment declined',
            'statusCode' => 400,
            'data' => ['paymentId' => self::PAYMENT_ID, 'orderId' => 'ORD-MB-1', 'status' => 'FAILED', 'paymentMethod' => 'MOBILE_BILLING'],
        ]);

        try {
            (new PaymentClient($api))->directMethodPayment($this->mobileBilling(), 'idem-decl');
            $this->fail('a decline must throw');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('payment.declined', $e->getErrorCode());
            $result = new MethodPaymentResult($e->getResponseBody() ?? []);
            $this->assertSame(self::PAYMENT_ID, $result->paymentId());
            $this->assertTrue($result->isFailed());
            $this->assertNull($result->action());
        }
        $this->assertSame(1, $http->callCount());
    }

    // --- Action endpoint ----------------------------------------------------------------

    public function testConfirmSendsOnlyTheCodeSigned(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => [
            'paymentId' => self::PAYMENT_ID, 'orderId' => 'ORD-MB-1', 'status' => 'CAPTURED', 'paymentMethod' => 'MOBILE_BILLING',
        ]]);

        $result = (new PaymentClient($api))->confirmPaymentAction(self::PAYMENT_ID, 'act_1', PaymentActionConfirmRequest::otp('123456'));

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/' . self::PAYMENT_ID . '/actions/act_1/confirm', $call['url']);
        $this->assertSame(['otp' => '123456'], self::body($http));
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertArrayNotHasKey('Idempotency-Key', $call['headers']);
        $this->assertTrue($result->isCaptured());
    }

    public function testConfirmCarriesConsentAndInputFieldsAndEncodesIds(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'data' => ['paymentId' => 'p/1', 'status' => 'CAPTURED']]);

        (new PaymentClient($api))->confirmPaymentAction(
            'p/1',
            'act 2',
            PaymentActionConfirmRequest::fields(['identityNumber' => '10000000146', 'birthDate' => '1990-01-31'])->withConsentAccepted(true)
        );

        $this->assertSame('https://gw.example.com/api/v1/payments/p%2F1/actions/act%202/confirm', $http->lastCall()['url']);
        $this->assertSame([
            'fields' => ['identityNumber' => '10000000146', 'birthDate' => '1990-01-31'],
            'consentAccepted' => true,
        ], self::body($http));
        $this->assertSame(['consentAccepted' => true], PaymentActionConfirmRequest::consent()->toArray());
    }

    public function testConfirmInputIsCheckedBeforeSending(): void
    {
        foreach ([
            static fn () => PaymentActionConfirmRequest::otp('12 34'),
            static fn () => PaymentActionConfirmRequest::otp(''),
            static fn () => PaymentActionConfirmRequest::fields([]),
            static fn () => PaymentActionConfirmRequest::fields(['providerCode' => 'X']),
            // K16: a flat associative array (ADDRESS) is valid; an array inside it is not.
            static fn () => PaymentActionConfirmRequest::fields(['nested' => ['a' => ['b' => 1]]]),
            static fn () => PaymentActionConfirmRequest::fields(['list' => [['inner' => [['a' => 1]]]]]),
        ] as $build) {
            try {
                $build();
                $this->fail('malformed confirm input accepted');
            } catch (ConfigurationException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(['otp', 'consentAccepted', 'fields'], PaymentMethod::PAYMENT_ACTION_CONFIRM_FIELDS);
        foreach ([PaymentActionConfirmRequest::otp('1')->withConsentAccepted(true), PaymentActionConfirmRequest::fields(['a' => 'b'])] as $request) {
            foreach (array_keys($request->toArray()) as $key) {
                $this->assertContains($key, PaymentMethod::PAYMENT_ACTION_CONFIRM_FIELDS, 'never token / checkoutSessionProof');
            }
        }
    }

    public function testConfirmRefusesEmptyIdsBeforeSending(): void
    {
        [$api, $http] = $this->api();

        foreach ([['', 'act_1'], [self::PAYMENT_ID, ' ']] as [$paymentId, $actionId]) {
            try {
                (new PaymentClient($api))->confirmPaymentAction($paymentId, $actionId, PaymentActionConfirmRequest::otp('1'));
                $this->fail('empty ids must be refused');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('actionId', $e->getMessage());
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testARejectedCodeIsA422WithTheRemainingAttempts(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(422, [
            'status' => 'failure',
            'code' => 'payment.action.input_rejected',
            'message' => 'Code rejected',
            'statusCode' => 422,
            'details' => ['attemptsRemaining' => 2],
            'data' => null,
        ]);

        try {
            (new PaymentClient($api))->confirmPaymentAction(self::PAYMENT_ID, 'act_1', PaymentActionConfirmRequest::otp('000000'));
            $this->fail('a rejected code must throw');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('payment.action.input_rejected', $e->getErrorCode());
            $this->assertSame(2, $e->getResponseBody()['details']['attemptsRemaining']);
        }
        $this->assertSame(1, $http->callCount());
    }

    public function testResendIsABodilessSignedPostReturningTheNewAction(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(202, self::otpEnvelope('act_2'));

        $result = (new PaymentClient($api))->resendPaymentAction(self::PAYMENT_ID, 'act_1');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/' . self::PAYMENT_ID . '/actions/act_1/resend', $call['url']);
        $this->assertNull($call['body']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertArrayNotHasKey('Digest', $call['headers']);
        $this->assertSame('act_2', $result->action()->id());
    }

    public function testOtpFlowStartResendConfirmWithTheNewActionId(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(202, self::otpEnvelope('act_1'))
            ->pushJson(202, self::otpEnvelope('act_2'))
            ->pushJson(200, ['status' => 'success', 'data' => ['paymentId' => self::PAYMENT_ID, 'status' => 'CAPTURED']]);
        $payments = new PaymentClient($api);

        $started = $payments->directMethodPayment($this->mobileBilling(), 'pay:ORD-MB-1');
        $resent = $payments->resendPaymentAction($started->paymentId(), $started->action()->id());
        $done = $payments->confirmPaymentAction($resent->paymentId(), $resent->action()->id(), PaymentActionConfirmRequest::otp('123456'));

        $paths = array_map(
            static fn (array $call): string => str_replace('https://gw.example.com/api/v1/payments', '', $call['url']),
            $http->calls
        );
        $this->assertSame([
            '/direct/payment',
            '/' . self::PAYMENT_ID . '/actions/act_1/resend',
            '/' . self::PAYMENT_ID . '/actions/act_2/confirm',
        ], $paths);
        $this->assertTrue($done->isCaptured());
    }

    // --- PaymentClient::listPaymentMethods ---------------------------------------------

    public function testListPaymentMethodsIsASignedGetWithTypedInputFields(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => ['methods' => [
            [
                'paymentMethod' => 'MOBILE_BILLING',
                'category' => 'TELCO',
                'blockName' => 'mobileBilling',
                'displayName' => 'Faturana Yansıt',
                'providerSelection' => 'OPTIONAL',
                'actionTypes' => ['OTP'],
                'currencies' => ['TRY'],
                'minAmount' => 100,
                'maxAmount' => 50000,
                'preauth' => false,
                'providers' => [[
                    'providerCode' => 'PAYCELL_MOBILE_BILLING',
                    'displayName' => 'Paycell Faturana Yansıt',
                    'logoUrl' => null,
                    'actionTypes' => ['OTP'],
                    'currencies' => ['TRY'],
                    'minAmount' => 100,
                    'maxAmount' => 50000,
                    'preauth' => false,
                    'inputFields' => [[
                        'key' => 'gsmNumber', 'type' => 'PHONE', 'required' => true,
                        'label' => ['tr' => 'Hat', 'en' => 'Line'], 'prefill' => 'buyer.gsmNumber', 'pii' => true,
                        'pattern' => null, 'minLength' => null, 'maxLength' => null, 'options' => [], 'url' => null,
                    ]],
                ]],
            ],
            ['paymentMethod' => 'CARD', 'blockName' => 'card', 'providerSelection' => 'ROUTED', 'providers' => []],
        ]]]);

        $list = (new PaymentClient($api))->listPaymentMethods();

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/methods', $call['url']);
        $this->assertArrayHasKey('X-Signature', $call['headers'], 'the methods route is behind the API-key signature');
        $this->assertCount(2, $list->methods());

        $method = $list->find(PaymentMethod::MOBILE_BILLING);
        $this->assertNotNull($method);
        $this->assertSame('mobileBilling', $method->blockName());
        $this->assertFalse($method->requiresProviderCode());
        $this->assertFalse($method->supportsPreauth());
        $provider = $method->provider('PAYCELL_MOBILE_BILLING');
        $this->assertNotNull($provider);
        $this->assertNull($provider->logoUrl());
        $field = $provider->inputFields()[0];
        $this->assertSame('gsmNumber', $field->key());
        $this->assertSame(MethodInputField::TYPE_PHONE, $field->type());
        $this->assertTrue($field->isRequired());
        $this->assertSame('buyer.gsmNumber', $field->prefill());
        $this->assertTrue($field->isPii());
        $this->assertSame('Line', $field->label('en'));
        $this->assertNull($list->find('WALLET'));
        $this->assertSame([], $list->find('CARD')->providers());
    }

    public function testListPaymentMethodsWithAMethodSignsTheQuery(): void
    {
        [$api, $http] = $this->api();
        $client = new PaymentClient($api);
        foreach ([PaymentMethod::MOBILE_BILLING, 'QR'] as $code) {
            $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => ['methods' => []]]);
            $this->assertSame([], $client->listPaymentMethods($code)->methods());
        }
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => ['methods' => []]]);
        $client->listPaymentMethods(null);

        $this->assertSame(3, $http->callCount());
        $this->assertSame('https://gw.example.com/api/v1/payments/methods', $http->lastCall()['url']);
    }

    public function testListPaymentMethodsSendsTheCanonicalCodeInTheSignedUrl(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => ['methods' => []]]);

        (new PaymentClient($api))->listPaymentMethods('QR');

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/methods?paymentMethod=QR_CODE', $call['url']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
    }

    /** @return array<string,array{0:string}> */
    public static function malformedMethodCodes(): array
    {
        return ['lower case' => ['mobile_billing'], 'empty' => [''], 'spaces' => ['MOBILE BILLING']];
    }

    #[DataProvider('malformedMethodCodes')]
    public function testListPaymentMethodsRefusesAMalformedCodeBeforeSending(string $code): void
    {
        [$api, $http] = $this->api();

        try {
            (new PaymentClient($api))->listPaymentMethods($code);
            $this->fail('a malformed code must throw');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('paymentMethod', $e->getMessage());
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testUnknownMethodCodeFromTheGatewayIsAnApiException(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(400, [
            'status' => 'failure',
            'code' => 'payment.method.unknown',
            'message' => 'Ödeme yöntemi tanınmıyor',
            'statusCode' => 400,
            'details' => ['field' => 'paymentMethod', 'allowed' => ['CARD', 'MOBILE_BILLING']],
        ]);

        try {
            (new PaymentClient($api))->listPaymentMethods('APPLE_PAY');
            $this->fail('an unknown code must throw');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('payment.method.unknown', $e->getErrorCode());
        }
    }

    public function testProviderSelectionModeIsReadFromTheMethodRow(): void
    {
        $platform = new PaymentMethodInfo([
            'paymentMethod' => 'MOBILE_BILLING',
            'providerSelection' => ProviderSelection::OPTIONAL,
            'providerSelectionMode' => ProviderSelectionMode::PLATFORM,
            'providers' => [['providerCode' => 'MB_PROVIDER_A'], ['providerCode' => 'MB_PROVIDER_B']],
        ]);
        $buyer = new PaymentMethodInfo([
            'paymentMethod' => 'WALLET',
            'providerSelection' => ProviderSelection::REQUIRED,
            'providerSelectionMode' => ProviderSelectionMode::BUYER,
            'providers' => [['providerCode' => 'WALLET_BRAND_A'], ['providerCode' => 'WALLET_BRAND_B']],
        ]);
        $older = new PaymentMethodInfo(['paymentMethod' => 'MOBILE_BILLING', 'providerSelection' => 'OPTIONAL', 'providers' => []]);

        $this->assertSame('PLATFORM', $platform->providerSelectionMode());
        $this->assertFalse($platform->requiresProviderCode(), 'PLATFORM with two providers: providerCode optional');
        $this->assertSame('BUYER', $buyer->providerSelectionMode());
        $this->assertTrue($buyer->requiresProviderCode());
        $this->assertNull($older->providerSelectionMode());
        $this->assertSame(['BUYER' => 'BUYER', 'PLATFORM' => 'PLATFORM'], (new \ReflectionClass(ProviderSelectionMode::class))->getConstants());
    }

    public function testPlatformMethodIsPaidWithoutProviderCode(): void
    {
        $body = (new DirectMethodPaymentRequest(5000, 'TRY', 'ORD-P-1'))
            ->withMethodFields(PaymentMethod::MOBILE_BILLING, null, ['gsmNumber' => '+905321234567'])
            ->toArray();

        $this->assertSame(['gsmNumber' => '+905321234567'], $body['mobileBilling']);
    }

    public function testNoneEligibleIsAnApiExceptionWithTheCandidates(): void
    {
        [$api, $http] = $this->api();
        $candidates = [
            ['providerCode' => 'MB_PROVIDER_A', 'outcome' => 'INELIGIBLE', 'reason' => 'AMOUNT_OUT_OF_RANGE'],
            ['providerCode' => 'MB_PROVIDER_B', 'outcome' => 'NOT_CHECKED', 'reason' => 'SELECTION_BUDGET_EXHAUSTED'],
        ];
        $http->pushJson(422, [
            'status' => 'failure',
            'code' => 'payment.provider.none_eligible',
            'message' => 'None of the connected providers can take this payment; the payment was not started and nothing was charged.',
            'statusCode' => 422,
            'docsUrl' => 'https://docs.lunixi.com/getting-started/errors#payment.provider.none_eligible',
            'details' => ['paymentMethod' => 'MOBILE_BILLING', 'candidates' => $candidates],
        ]);

        try {
            (new PaymentClient($api))->directMethodPayment($this->mobileBilling(), 'idem-none-eligible');
            $this->fail('none_eligible must throw');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('payment.provider.none_eligible', $e->getErrorCode());
            $this->assertSame($candidates, ($e->getResponseBody() ?? [])['details']['candidates']);
        }
        $this->assertSame(1, $http->callCount());
    }

    // --- Query and card endpoints ----------------------------------------------------------

    public function testPaymentIntentReadsMethodAndOpenAction(): void
    {
        $payment = new PaymentIntent([
            'id' => self::PAYMENT_ID,
            'status' => 'INITIATED',
            'paymentMethod' => 'MOBILE_BILLING',
            'action' => ['id' => 'act_1', 'type' => 'OTP', 'expiresAt' => '2026-10-11T10:05:00.000Z'],
        ]);
        $this->assertSame('MOBILE_BILLING', $payment->paymentMethod());
        $this->assertSame('act_1', $payment->action()->id());

        $card = new PaymentIntent(['id' => 'p', 'paymentMethod' => '', 'action' => null]);
        $this->assertNull($card->paymentMethod());
        $this->assertNull($card->action());
    }

    public function testCardGoesToDirectPaymentWithForce3DOnlyWhenAsked(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'data' => []])->pushJson(202, ['status' => 'success', 'data' => []]);
        $request = static fn (): DirectMethodPaymentRequest => (new DirectMethodPaymentRequest(10050, 'TRY', 'ORD-D-1'))
            ->withBuyer(self::buyer())
            ->withBillingAddress(self::address())
            ->withBasketItems([self::basketItem(10050)]);
        $payments = new PaymentClient($api);

        $payments->directMethodPayment($request()->withCard($this->card()), 'k-card');
        $payments->directMethodPayment($request()->withCard($this->card())->withForce3D(true), 'k-card-3d');

        $this->assertSame([
            'https://gw.example.com/api/v1/payments/direct/payment',
            'https://gw.example.com/api/v1/payments/direct/payment',
        ], array_column($http->calls, 'url'));
        [$plain, $secure] = array_map(
            static fn (array $call): array => json_decode((string) $call['body'], true),
            $http->calls
        );
        foreach ([$plain, $secure] as $body) {
            $this->assertSame('CARD', $body['paymentMethod']);
            $this->assertSame(10050, $body['amount']);
            $this->assertArrayHasKey('card', $body);
        }
        $this->assertArrayNotHasKey('force3D', $plain);
        $this->assertTrue($secure['force3D']);
    }

    public function testStoredCardIsChargedThroughDirectPayment(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'data' => []]);
        $request = (new DirectMethodPaymentRequest(10050, 'TRY', 'ORD-S-1'))
            ->withCard(new CardDetails(['cardUserKey' => 'cust_1', 'cardToken' => 'ct_1', 'cvcNumber' => '123']));

        (new PaymentClient($api))->directMethodPayment($request, 'k-stored');

        $call = $http->calls[0];
        $this->assertSame('https://gw.example.com/api/v1/payments/direct/payment', $call['url']);
        $this->assertSame(
            ['cvcNumber' => '123', 'cardUserKey' => 'cust_1', 'cardToken' => 'ct_1'],
            json_decode((string) $call['body'], true)['card']
        );
    }

    public function testDirect2d3dCardMethodsAreRemoved(): void
    {
        foreach (['chargeCard', 'chargeCard2d', 'chargeCard3d'] as $method) {
            $this->assertFalse(method_exists(PaymentClient::class, $method), $method);
        }
    }
}
