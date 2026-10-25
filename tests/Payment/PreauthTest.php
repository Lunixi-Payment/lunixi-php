<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Marketplace\MarketplaceClient;
use Lunixi\Sdk\Marketplace\MarketplaceSeller;
use Lunixi\Sdk\Payment\Address;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\Buyer;
use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\DirectPaymentRequest;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pre-authorization is the typed `preauth` body field. No SDK path writes
 * `metadata.autoCapture` — the deprecated withAutoCapture() included.
 */
final class PreauthTest extends TestCase
{
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

    /** @return array<string,mixed> */
    private static function body(FakeHttpClient $http): array
    {
        return json_decode((string) $http->lastCall()['body'], true);
    }

    // --- Builders ------------------------------------------------------------------

    public function testIntentWithPreauthWritesTheTypedFieldOnly(): void
    {
        $body = (new CreateIntentRequest(1000, 'TRY', 'WC-1'))
            ->withMetadata(['wc_order' => '1'])
            ->withPreauth(true)
            ->toArray();

        $this->assertTrue($body['preauth']);
        $this->assertSame(['wc_order' => '1'], $body['metadata']);
    }

    public function testIntentWithPreauthFalseIsAnExplicitFalse(): void
    {
        $body = (new CreateIntentRequest(1000, 'TRY', 'WC-2'))->withPreauth(false)->toArray();

        $this->assertFalse($body['preauth']);
        $this->assertArrayNotHasKey('metadata', $body);
    }

    public function testIntentWithoutPreauthSendsNoField(): void
    {
        $body = (new CreateIntentRequest(1000, 'TRY', 'WC-3'))->toArray();

        $this->assertArrayNotHasKey('preauth', $body);
    }

    public function testDeprecatedWithAutoCaptureFalseIsPreauthTrueWithoutMetadata(): void
    {
        $body = (new CreateIntentRequest(1000, 'TRY', 'WC-4'))->withAutoCapture(false)->toArray();

        $this->assertTrue($body['preauth']);
        $this->assertArrayNotHasKey('metadata', $body, 'withAutoCapture() no longer writes metadata.autoCapture');
    }

    public function testDeprecatedWithAutoCaptureTrueIsPreauthFalse(): void
    {
        $body = (new CreateIntentRequest(1000, 'TRY', 'WC-5'))->withAutoCapture(true)->toArray();

        $this->assertFalse($body['preauth']);
        $this->assertArrayNotHasKey('metadata', $body);
    }

    public function testDeprecatedWithAutoCaptureLeavesMerchantMetadataUntouched(): void
    {
        $body = (new CreateIntentRequest(1000, 'TRY', 'WC-6'))
            ->withMetadata(['wc_order' => '6'])
            ->withAutoCapture(false)
            ->toArray();

        $this->assertTrue($body['preauth']);
        $this->assertSame(['wc_order' => '6'], $body['metadata']);
    }

    public function testWithAutoCaptureIsMarkedDeprecated(): void
    {
        $doc = (string) (new \ReflectionMethod(CreateIntentRequest::class, 'withAutoCapture'))->getDocComment();

        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString('withPreauth', $doc);
    }

    public function testDirectRequestWithPreauth(): void
    {
        $this->assertTrue($this->directRequest()->withPreauth(true)->toArray()['preauth']);
        $this->assertFalse($this->directRequest()->withPreauth(false)->toArray()['preauth']);
        $this->assertArrayNotHasKey('preauth', $this->directRequest()->toArray());
    }

    // --- Wire: PaymentClient ---------------------------------------------------------

    public function testCreateIntentSendsPreauthOnTheWire(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(201, ['success' => true, 'paymentId' => 'pi_1', 'token' => 'tok_1', 'checkoutFormContent' => '']);

        (new PaymentClient($api))->createIntent((new CreateIntentRequest(1000, 'TRY', 'WC-7'))->withAutoCapture(false), 'idem-7');

        $this->assertStringEndsWith('/api/v1/payments/intents', $http->lastCall()['url']);
        $body = self::body($http);
        $this->assertTrue($body['preauth']);
        $this->assertArrayNotHasKey('metadata', $body);
    }

    /** @return array<string,array{0:bool}> */
    public static function cardModes(): array
    {
        return ['without 3D Secure' => [false], 'force3D' => [true]];
    }

    #[DataProvider('cardModes')]
    public function testCardDirectPaymentSendsPreauthOnTheWire(bool $force3D): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'data' => ['paymentId' => 'pi_d', 'status' => 'AUTHORIZED']]);
        $request = (new DirectMethodPaymentRequest(1000, 'TRY', 'o1'))
            ->withCard(new CardDetails(['cardNumber' => '5400000000000004']))
            ->withPreauth(true);
        if ($force3D) {
            $request->withForce3D(true);
        }

        (new PaymentClient($api))->directMethodPayment($request, 'idem-d');

        $this->assertStringEndsWith('/api/v1/payments/direct/payment', $http->lastCall()['url']);
        $body = self::body($http);
        $this->assertSame('CARD', $body['paymentMethod']);
        $this->assertTrue($body['preauth']);
        $this->assertArrayNotHasKey('metadata', $body);
    }

    // --- Wire: marketplace -----------------------------------------------------------

    public function testMarketplaceIntentCarriesPreauth(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(201, ['success' => true, 'paymentId' => 'pi_m', 'token' => 'tok_m', 'checkoutFormContent' => '<script></script>']);

        (new MarketplaceClient($api))->createCheckoutIntent(
            (new CreateIntentRequest(100000, 'TRY', 'WC-8'))->withPreauth(true),
            [new MarketplaceSeller('d_alice', 100000)],
            MarketplaceClient::POLICY_ALL_OR_NOTHING,
            'idem-mp-8'
        );

        $this->assertStringEndsWith('/api/v1/payments/marketplace/intents', $http->lastCall()['url']);
        $body = self::body($http);
        $this->assertTrue($body['preauth']);
        $this->assertArrayNotHasKey('metadata', $body);
    }

    /** @return array<string,array{0:bool,1:string}> */
    public static function marketplaceRoutes(): array
    {
        return ['2d' => [false, '/api/v1/payments/marketplace/2d'], '3d' => [true, '/api/v1/payments/marketplace/3d']];
    }

    #[DataProvider('marketplaceRoutes')]
    public function testTypedMarketplaceChargeSendsPreauthAndSellerLegs(bool $threeDS, string $path): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'pi_m', 'status' => 'AUTHORIZED']]);
        $marketplace = new MarketplaceClient($api);
        $request = $this->directRequest()->withPreauth(true);
        $sellers = [new MarketplaceSeller('d_alice', 600), new MarketplaceSeller('d_bob', 400)];

        $threeDS
            ? $marketplace->chargeMarketplaceCard3d($request, $sellers, MarketplaceClient::POLICY_PARTIAL_ALLOWED, 'idem-mp')
            : $marketplace->chargeMarketplaceCard2d($request, $sellers, MarketplaceClient::POLICY_PARTIAL_ALLOWED, 'idem-mp');

        $call = $http->lastCall();
        $this->assertStringEndsWith($path, $call['url']);
        $this->assertArrayHasKey('X-Signature', $call['headers'], 'marketplace charge is step-up');
        $this->assertSame('idem-mp', $call['headers']['Idempotency-Key']);
        $body = self::body($http);
        $this->assertTrue($body['preauth']);
        $this->assertArrayNotHasKey('metadata', $body);
        $this->assertSame('PARTIAL_ALLOWED', $body['multiSellerPolicy']);
        $this->assertSame(
            [['subDealerId' => 'd_alice', 'amountMinor' => '600'], ['subDealerId' => 'd_bob', 'amountMinor' => '400']],
            $body['sellers']
        );
        $this->assertSame('5400000000000004', $body['card']['cardNumber']);
    }

    public function testTypedMarketplaceChargeRequiresASellerLeg(): void
    {
        [$api, $http] = $this->api();

        $this->expectException(ConfigurationException::class);
        try {
            (new MarketplaceClient($api))->chargeMarketplaceCard2d($this->directRequest(), []);
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testRawMarketplaceChargeRefusesNonBooleanPreauth(): void
    {
        [$api, $http] = $this->api();
        $payload = array_merge($this->directRequest()->toArray(), [
            'preauth' => 1,
            'sellers' => [['subDealerId' => 'd_alice', 'amountMinor' => '1000']],
        ]);

        $this->expectException(ConfigurationException::class);
        try {
            (new MarketplaceClient($api))->chargeMarketplaceCard($payload, false, 'idem-raw-mp');
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }
}
