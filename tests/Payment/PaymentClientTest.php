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
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\DirectPaymentRequest;
use Lunixi\Sdk\Payment\InstallmentOptionsRequest;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Payment\PaymentStatus;
use Lunixi\Sdk\Payment\StoreCardRequest;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class PaymentClientTest extends TestCase
{
    /** @return array{0:PaymentClient,1:FakeHttpClient} */
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

        return [new PaymentClient(new ApiClient($config, $signer, $tokens, $http)), $http];
    }

    private function directRequest(): DirectPaymentRequest
    {
        return new DirectPaymentRequest(
            1000,
            'TRY',
            'o1',
            new CardDetails(['cardNumber' => '5400000000000004']),
            new Buyer([
                'name' => 'Ada', 'surname' => 'L', 'identityNumber' => '11111111111',
                'email' => 'a@b.co', 'gsmNumber' => '+90555', 'city' => 'Istanbul',
                'country' => 'TR', 'zipCode' => '34000', 'ip' => '1.2.3.4',
            ]),
            new Address([
                'address' => 'Street 1', 'zipCode' => '34000', 'contactName' => 'Ada',
                'city' => 'Istanbul', 'country' => 'TR',
            ]),
            [
                new BasketItem(['id' => 'i1', 'price' => 1000, 'name' => 'Widget', 'category1' => 'Cat', 'itemType' => BasketItem::TYPE_PHYSICAL]),
            ]
        );
    }

    public function testCreateIntentSendsStepUpBodyAndReturnsToken(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'code' => 'OK', 'paymentId' => 'pi_1', 'token' => 'tok_1', 'checkoutFormContent' => '']);

        $request = (new CreateIntentRequest(1000, 'try', 'WC-1042'))
            ->withInstallment(3)
            ->withCallbackUrl('https://shop/cb');
        $intent = $payments->createIntent($request);

        $this->assertSame('pi_1', $intent->paymentId());
        $this->assertSame('tok_1', $intent->token());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/payments/intents', $call['url']);
        $this->assertArrayHasKey('X-Signature', $call['headers']); // step-up
        $body = json_decode((string) $call['body'], true);
        $this->assertSame(1000, $body['amount']);
        $this->assertSame('TRY', $body['currency']);
        $this->assertSame('WC-1042', $body['orderId']);
        $this->assertSame(3, $body['installment']);
        $this->assertSame('https://shop/cb', $body['callbackUrl']);
    }

    public function testCreateIntentWithoutTokenThrows(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => false, 'code' => 'CHECKOUT_INITIALIZE_FAILED', 'message' => 'nope']);

        try {
            $payments->createIntent(new CreateIntentRequest(1000, 'TRY', 'o1'));
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('CHECKOUT_INITIALIZE_FAILED', $e->getErrorCode());
        }
    }

    public function testCapturePartialSendsAmountAndIdempotencyKey(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'pi_1', 'status' => 'PARTIALLY_CAPTURED', 'amount' => 1000, 'currency' => 'TRY']]);

        $intent = $payments->capture('pi_1', 500, 'idem-cap-1');

        $this->assertSame(PaymentStatus::PARTIALLY_CAPTURED, $intent->status());
        $this->assertTrue($intent->isCaptured());

        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/payments/pi_1/capture', $call['url']);
        $this->assertSame('idem-cap-1', $call['headers']['Idempotency-Key']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('pi_1', $body['paymentIntentId']);
        $this->assertSame(500, $body['amount']);
    }

    public function testCaptureRequiresIdempotencyKey(): void
    {
        [$payments] = $this->make();
        $this->expectException(ConfigurationException::class);
        $payments->capture('pi_1');
    }

    public function testRefundSendsAmountAndReason(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'pi_1', 'status' => 'PARTIALLY_REFUNDED']]);

        $intent = $payments->refund('pi_1', 300, 'customer return', 'idem-ref-1');

        $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED, $intent->status());
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/payments/pi_1/refund', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame(300, $body['amount']);
        $this->assertSame('customer return', $body['reason']);
    }

    public function testVoidSendsNoBody(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'pi_1', 'status' => 'VOIDED']]);

        $intent = $payments->void('pi_1', 'idem-void-1');

        $this->assertTrue($intent->isVoided());
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/payments/pi_1/void', $call['url']);
        $this->assertNull($call['body']);
        $this->assertSame('idem-void-1', $call['headers']['Idempotency-Key']);
    }

    public function testGetUsesBearerOnlyAndMapsIntent(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            'id' => 'pi_9', 'status' => 'AWAITING_3D', 'amount' => 2500, 'currency' => 'TRY',
            'is3D' => true, 'threeDRedirectUrl' => 'https://bank/3ds',
            'metadataJson' => '{"wc_order":"42"}',
        ]]);

        $intent = $payments->get('pi_9');

        $this->assertSame('pi_9', $intent->id());
        $this->assertTrue($intent->awaiting3D());
        $this->assertSame('https://bank/3ds', $intent->threeDRedirectUrl());
        $this->assertSame('42', $intent->metadata()['wc_order']);

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'GET is bearer-only, not step-up');
    }

    public function testListBuildsQueryAndPagination(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, [
            'success' => true,
            'data' => [['id' => 'pi_1', 'status' => 'CAPTURED'], ['id' => 'pi_2', 'status' => 'FAILED']],
            'total' => 2, 'page' => 1, 'limit' => 20, 'totalPages' => 1,
        ]);

        $list = $payments->list(['status' => 'CAPTURED', 'limit' => 20]);

        $this->assertCount(2, $list->items());
        $this->assertSame(2, $list->total());
        $this->assertSame('pi_1', $list->items()[0]->id());
        $this->assertStringContainsString('status=CAPTURED', $http->lastCall()['url']);
        $this->assertStringContainsString('limit=20', $http->lastCall()['url']);
    }

    public function testChargeCardDirectIsStepUpSigned(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['paymentId' => 'pi_d']]);

        $payments->chargeCard($this->directRequest()->toArray(), true, 'idem-direct-raw');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/payments/direct/3d', $call['url']);
        $this->assertArrayHasKey('X-Signature', $call['headers'], 'direct charge must be step-up signed');
        $this->assertSame('idem-direct-raw', $call['headers']['Idempotency-Key']);
    }

    public function testRawDirectChargeRequiresBusinessContext(): void
    {
        [$payments] = $this->make();

        $this->expectException(ConfigurationException::class);
        $payments->chargeCard([
            'paidPrice' => 1000,
            'currency' => 'TRY',
            'orderId' => 'o1',
            'card' => ['cardNumber' => '5400000000000004'],
        ], true, 'idem-direct-missing-context');
    }

    public function testDirectChargeRequiresIdempotencyKey(): void
    {
        [$payments] = $this->make();

        $this->expectException(ConfigurationException::class);
        $payments->chargeCard3d($this->directRequest());
    }

    public function testTypedDirectPaymentRequestUsesDirect3dRoute(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'pi_d', 'status' => 'AWAITING_3D']]);

        $payments->chargeCard3d(
            $this->directRequest(),
            'idem-direct-3d'
        );

        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/payments/direct/3d', $call['url']);
        $this->assertSame('idem-direct-3d', $call['headers']['Idempotency-Key']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame(1000, $body['paidPrice']);
        $this->assertSame('TRY', $body['currency']);
        $this->assertSame('Ada', $body['buyer']['name']);
        $this->assertSame('Widget', $body['basketItems'][0]['name']);
    }

    public function testBinInstallmentsAndStoredCardRoutes(): void
    {
        [$payments, $http] = $this->make();

        $http->pushJson(200, ['success' => true, 'data' => ['found' => true]]);
        $payments->binInfo('54000000');
        $this->assertStringEndsWith('/api/v1/bin/info', $http->lastCall()['url']);

        $http->pushJson(200, ['success' => true, 'data' => ['binding' => true]]);
        $payments->installmentOptions((new InstallmentOptionsRequest(1000, 'TRY'))->withBinOrPan('54000000')->withInstallment(3));
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/bin/installments', $call['url']);
        $this->assertSame(3, json_decode((string) $call['body'], true)['installment']);

        $http->pushJson(200, ['success' => true, 'data' => ['storedCardToken' => 'card_1']]);
        $payments->storeCard(
            new StoreCardRequest(new CardDetails(['cardNumber' => '5400000000000004']), 'cust_1', 'https://shop/cards/cb'),
            'idem-store-card'
        );
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/payments/cards', $call['url']);
        $this->assertSame('idem-store-card', $call['headers']['Idempotency-Key']);

        $http->pushJson(200, ['cards' => []]);
        $payments->listStoredCards('cust_1');
        $this->assertStringContainsString('/api/v1/payments/cards?cardUserKey=cust_1', $http->lastCall()['url']);

        $http->pushJson(200, ['success' => true]);
        $payments->deactivateStoredCard('card_1', 'idem-delete-card');
        $call = $http->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertStringEndsWith('/api/v1/payments/cards/card_1', $call['url']);
    }

    public function testFailoverRecoveryAnalyticsRoute(): void
    {
        [$payments, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['rescued' => 3]]);

        $payments->failoverRecoveryAnalytics(['range' => '30d']);
        $this->assertStringContainsString('/api/v1/payments/analytics/failover-recovery', $http->lastCall()['url']);
    }
}
