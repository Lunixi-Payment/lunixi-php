<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Commerce\CheckoutService;
use Lunixi\Sdk\Commerce\OrderSnapshot;
use Lunixi\Sdk\Commerce\PdoLedgerStore;
use Lunixi\Sdk\Commerce\SecretBox;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Marketplace\MarketplaceSeller;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\Buyer;
use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\OrderDetails;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\SplitPaymentRequest;
use Lunixi\Sdk\Payment\StoreCardRequest;
use Lunixi\Sdk\Subscription\UpsertCustomerRequest;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\OrderDetailsFixture;
use PHPUnit\Framework\TestCase;

/**
 * Order details every payment requires (OrderDetails): the buyer, the billing
 * address and at least one basket item; a PHYSICAL item also needs the
 * shipping address. Every sender refuses an incomplete order before anything
 * is sent; the paths match the gateway's `details.field`.
 */
final class OrderDetailsTest extends TestCase
{
    /** @return array{0:LunixiClient,1:FakeHttpClient} */
    private function client(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration(['baseUrl' => 'https://gw.example.com', 'keyId' => 'kid_1', 'privateKey' => $keys['privateKey']]);
        $tokens = new InMemoryTokenStore();
        $tokens->set($config->tokenCacheKey(), 'bearer_x', 3600);
        $http = new FakeHttpClient();

        return [new LunixiClient($config, $http, $tokens), $http];
    }

    /** @return array{0:CheckoutService,1:FakeHttpClient,2:PdoLedgerStore} */
    private function checkoutService(): array
    {
        [$client, $http] = $this->client();
        $store = new PdoLedgerStore(new \PDO('sqlite::memory:'));
        $store->install();

        return [new CheckoutService($client, $store, new SecretBox(str_repeat('k', 32))), $http, $store];
    }

    /** @return array<string,mixed> */
    private static function body(FakeHttpClient $http): array
    {
        return json_decode((string) $http->lastCall()['body'], true);
    }

    private static function assertRefused(callable $send, string $message, FakeHttpClient $http): void
    {
        try {
            $send();
            self::fail('An incomplete order was accepted: ' . $message);
        } catch (ConfigurationException $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertSame(0, $http->callCount(), 'nothing reached the gateway');
    }

    // --- OrderDetails::missing --------------------------------------------------------

    public function testACompleteBodyMissesNothing(): void
    {
        $this->assertSame([], OrderDetails::missing(OrderDetailsFixture::orderDetails('1000')));
        $this->assertSame([], OrderDetails::missing(OrderDetailsFixture::orderDetails('1000', BasketItem::TYPE_PHYSICAL)));
        $this->assertSame(['buyer', 'billingAddress', 'basketItems'], OrderDetails::ORDER_DETAILS_REQUIRED_FIELDS);
    }

    public function testTheMissingFieldsAreNamedByTheirDottedPath(): void
    {
        $complete = OrderDetailsFixture::orderDetails('1000');

        $noEmail = $complete;
        unset($noEmail['buyer']['email']);
        $this->assertSame(['buyer.email'], OrderDetails::missing($noEmail));

        $blankName = $complete;
        $blankName['buyer']['name'] = '   ';
        $this->assertSame(['buyer.name'], OrderDetails::missing($blankName));

        $noBilling = $complete;
        unset($noBilling['billingAddress']);
        $this->assertSame(['billingAddress'], OrderDetails::missing($noBilling));

        $noBasket = $complete;
        unset($noBasket['basketItems']);
        $this->assertSame(['basketItems'], OrderDetails::missing($noBasket));
        $this->assertSame(['basketItems'], OrderDetails::missing(['basketItems' => []] + $complete));

        $noPrice = $complete;
        unset($noPrice['basketItems'][0]['price']);
        $this->assertSame(['basketItems.0.price'], OrderDetails::missing($noPrice));

        $this->assertSame(['buyer', 'billingAddress', 'basketItems'], OrderDetails::missing([]));
    }

    public function testAPhysicalItemNeedsACompleteShippingAddress(): void
    {
        $physical = OrderDetailsFixture::orderDetails('1000', BasketItem::TYPE_PHYSICAL);

        $noShipping = $physical;
        unset($noShipping['shippingAddress']);
        $this->assertSame(['shippingAddress'], OrderDetails::missing($noShipping));

        $noCity = $physical;
        unset($noCity['shippingAddress']['city']);
        $this->assertSame(['shippingAddress.city'], OrderDetails::missing($noCity));

        // A virtual basket needs no shipping address, but one that is sent must be complete.
        $virtual = OrderDetailsFixture::orderDetails('1000');
        $this->assertArrayNotHasKey('shippingAddress', $virtual);
        $this->assertSame([], OrderDetails::missing($virtual));
        $partial = $virtual + ['shippingAddress' => ['contactName' => 'Ada Yilmaz']];
        $this->assertSame(
            ['shippingAddress.address', 'shippingAddress.city', 'shippingAddress.country', 'shippingAddress.zipCode'],
            OrderDetails::missing($partial)
        );
    }

    public function testWithoutTheBasketOnlyTheBuyerAndTheBillingAddressAreChecked(): void
    {
        $this->assertSame([], OrderDetails::missing(OrderDetailsFixture::orderDetails(), false));
        $this->assertSame(['basketItems'], OrderDetails::missing(OrderDetailsFixture::orderDetails()));
        $this->assertSame(['buyer', 'billingAddress'], OrderDetails::missing([], false));
    }

    public function testAssertCompleteNamesEveryMissingField(): void
    {
        OrderDetails::assertComplete(OrderDetailsFixture::orderDetails('1000'), 'A payment');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('A payment requires the order details: buyer.email, basketItems.');
        $body = OrderDetailsFixture::orderDetails();
        unset($body['buyer']['email']);
        OrderDetails::assertComplete($body, 'A payment');
    }

    public function testACheckoutIntentMayLeaveOutTheBuyerIp(): void
    {
        $body = OrderDetailsFixture::orderDetails('1000');
        unset($body['buyer']['ip']);

        $this->assertSame([], OrderDetails::missing($body, true, true), 'the Lunixi form takes the IP from the browser');
        $this->assertSame(['buyer.ip'], OrderDetails::missing($body));
        $this->assertSame(['ip'], OrderDetails::CHECKOUT_INTENT_BUYER_OPTIONAL_FIELDS);
        $buyer = $body['buyer'];
        $this->assertArrayNotHasKey('ip', (new Buyer($buyer))->toArray(), 'Buyer itself no longer requires ip');
    }

    // --- Every sender refuses an incomplete order before sending ----------------------

    public function testCreateIntentRefusesAnIncompleteOrder(): void
    {
        [$client, $http] = $this->client();
        $request = (new CreateIntentRequest('1000', 'TRY', 'WC-1'))
            ->withBuyer(OrderDetailsFixture::buyer())
            ->withBasketItems([OrderDetailsFixture::basketItem('1000')]);

        self::assertRefused(
            static fn () => $client->payments()->createIntent($request, 'idem-1'),
            'A payment intent requires the order details: billingAddress.',
            $http
        );
    }

    public function testDirectMethodPaymentRefusesAnIncompleteOrder(): void
    {
        [$client, $http] = $this->client();
        $request = (new DirectMethodPaymentRequest('5000', 'TRY', 'ORD-MB-1'))
            ->withMethodFields(PaymentMethod::MOBILE_BILLING, null, ['gsmNumber' => '+905350000000'])
            ->withBuyer(OrderDetailsFixture::buyer())
            ->withBillingAddress(OrderDetailsFixture::address());

        self::assertRefused(
            static fn () => $client->payments()->directMethodPayment($request, 'pay:ORD-MB-1'),
            'A direct method payment requires the order details: basketItems.',
            $http
        );
    }

    public function testStoreCardNeedsTheBuyerAndTheBillingAddressButNoBasket(): void
    {
        [$client, $http] = $this->client();
        $card = new CardDetails(['cardNumber' => '5400000000000004']);
        $incomplete = (new StoreCardRequest($card, 'cust_1', 'https://shop.example/cards/cb'))->withBuyer(OrderDetailsFixture::buyer());

        self::assertRefused(
            static fn () => $client->payments()->storeCard($incomplete, 'idem-store'),
            'Storing a card requires the order details: billingAddress.',
            $http
        );

        $http->pushJson(200, ['success' => true, 'data' => ['threeDRedirectUrl' => 'https://bank.example/3ds']]);
        $client->payments()->storeCard(OrderDetailsFixture::complete(new StoreCardRequest($card, 'cust_1', 'https://shop.example/cards/cb')), 'idem-store');
        $body = self::body($http);
        $this->assertSame(OrderDetailsFixture::BUYER, $body['buyer']);
        $this->assertSame(OrderDetailsFixture::ADDRESS, $body['billingAddress']);
        $this->assertArrayNotHasKey('basketItems', $body);
    }

    public function testASplitPaymentRequestRefusesAnIncompleteOrder(): void
    {
        [$client, $http] = $this->client();
        $request = (new SplitPaymentRequest('10000', 'TRY', 'cuk_1'))->addCardLeg('ct_1', '10000');

        self::assertRefused(
            static fn () => $request->toArray(),
            'A split payment requires the order details: buyer, billingAddress, basketItems.',
            $http
        );
        self::assertRefused(
            static fn () => $client->payments()->createSplitPayment($request, 'split:1'),
            'A split payment requires the order details: buyer, billingAddress, basketItems.',
            $http
        );

        $body = OrderDetailsFixture::complete($request, '10000', BasketItem::TYPE_PHYSICAL)->toArray();
        $this->assertSame(OrderDetailsFixture::ADDRESS, $body['shippingAddress']);
        $this->assertSame([OrderDetailsFixture::basketItemArray('10000', BasketItem::TYPE_PHYSICAL)], $body['basketItems']);
    }

    public function testTheMarketplaceSendersRefuseAnIncompleteOrder(): void
    {
        [$client, $http] = $this->client();
        $sellers = [['subDealerId' => 'd_1', 'amountMinor' => '1000']];
        $physical = OrderDetailsFixture::orderDetails('1000', BasketItem::TYPE_PHYSICAL);
        unset($physical['shippingAddress']);
        $charge = ['amount' => '1000', 'currency' => 'TRY', 'orderId' => 'o1', 'card' => ['cardNumber' => '5400000000000004'], 'sellers' => $sellers] + $physical;

        self::assertRefused(
            static fn () => $client->payments()->createMarketplaceIntent(['amount' => '1000', 'currency' => 'TRY', 'orderId' => 'o1', 'sellers' => $sellers], 'k-mp'),
            'A marketplace payment intent requires the order details: buyer, billingAddress, basketItems.',
            $http
        );
        self::assertRefused(
            static fn () => $client->marketplace()->createCheckoutIntent(
                (new CreateIntentRequest('1000', 'TRY', 'o1'))->withBuyer(OrderDetailsFixture::buyer())->withBillingAddress(OrderDetailsFixture::address()),
                [new MarketplaceSeller('d_1', '1000')]
            ),
            'A marketplace payment intent requires the order details: basketItems.',
            $http
        );
        foreach ([false, true] as $threeDS) {
            self::assertRefused(
                static fn () => $client->marketplace()->chargeMarketplaceCard($charge, $threeDS, 'k-mp-charge'),
                'A marketplace card charge requires the order details: shippingAddress.',
                $http
            );
        }
    }

    // --- OrderSnapshot and CheckoutService ----------------------------------------------

    public function testTheSnapshotOrderDetailsAreImmutableAndOutsideTheIdentity(): void
    {
        $base = new OrderSnapshot('merchant-1', 'OC-123', '1990', 'TRY');
        $detailed = OrderDetailsFixture::snapshot($base, BasketItem::TYPE_PHYSICAL);

        $this->assertNull($base->buyer());
        $this->assertNull($base->billingAddress());
        $this->assertNull($base->shippingAddress());
        $this->assertSame([], $base->basketItems());
        $this->assertSame(OrderDetailsFixture::BUYER, $detailed->buyer() !== null ? $detailed->buyer()->toArray() : null);
        $this->assertCount(1, $detailed->basketItems());

        $this->assertSame($base->fingerprint(), $detailed->fingerprint(), 'the order details do not change the fingerprint');
        $this->assertSame($base->toArray(), $detailed->toArray());

        $this->expectException(\InvalidArgumentException::class);
        $base->withBasketItems([OrderDetailsFixture::basketItemArray('1990')]);
    }

    public function testCheckoutCarriesTheOrderDetailsToTheIntent(): void
    {
        [$service, $http] = $this->checkoutService();
        $base = new OrderSnapshot('merchant-1', 'OC-123', '1990', 'TRY');
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);

        $service->start('123', OrderDetailsFixture::snapshot($base, BasketItem::TYPE_PHYSICAL), 'https://shop.example/return');

        $this->assertStringEndsWith('/api/v1/payments/intents', $http->lastCall()['url']);
        $body = self::body($http);
        $this->assertSame(OrderDetailsFixture::BUYER, $body['buyer']);
        $this->assertSame(OrderDetailsFixture::ADDRESS, $body['billingAddress']);
        $this->assertSame(OrderDetailsFixture::ADDRESS, $body['shippingAddress']);
        $this->assertSame([OrderDetailsFixture::basketItemArray('1990', BasketItem::TYPE_PHYSICAL)], $body['basketItems']);
        $this->assertSame($base->fingerprint(), $body['metadata']['lunixiCommerce']['fingerprint']);
    }

    public function testCheckoutRefusesAnIncompleteOrderBeforeTheReservation(): void
    {
        [$service, $http, $store] = $this->checkoutService();

        self::assertRefused(
            static fn () => $service->start('123', new OrderSnapshot('merchant-1', 'OC-123', '1990', 'TRY'), 'https://shop.example/return'),
            'A checkout requires the order details: buyer, billingAddress, basketItems.',
            $http
        );
        $this->assertNull($store->transaction('123', static fn () => $store->load('123')), 'no attempt was reserved');
    }

    // --- UpsertCustomerRequest ------------------------------------------------------------

    public function testASubscriptionCustomerNeedsTheBuyerAndTheBillingAddress(): void
    {
        [$client, $http] = $this->client();
        $request = (new UpsertCustomerRequest('wp-42'))->withName('Ada')->withEmail('ada@example.com')->withSurname(' ');

        self::assertRefused(
            static fn () => $client->subscriptions()->customers()->upsert($request, 'cus-wp-42'),
            'A subscription customer requires surname, gsmNumber, identityNumber, billingAddress, billingCity, billingCountry, billingZipCode.',
            $http
        );
        $this->assertSame([
            'name', 'surname', 'email', 'gsmNumber', 'identityNumber',
            'billingAddress', 'billingCity', 'billingCountry', 'billingZipCode',
        ], UpsertCustomerRequest::REQUIRED_FIELDS);
    }

    public function testACompleteSubscriptionCustomerIsSentAsTheGatewayFields(): void
    {
        [$client, $http] = $this->client();
        $buyer = OrderDetailsFixture::BUYER;
        $address = OrderDetailsFixture::ADDRESS;
        $request = (new UpsertCustomerRequest('wp-42'))
            ->withName($buyer['name'])
            ->withSurname($buyer['surname'])
            ->withEmail($buyer['email'])
            ->withPhone($buyer['gsmNumber'])
            ->withIdentityNumber($buyer['identityNumber'])
            ->withBillingAddress($address['address'], $address['city'], $address['country'], $address['zipCode']);

        $this->assertSame([
            'externalId' => 'wp-42',
            'name' => 'Ada',
            'surname' => 'Yilmaz',
            'email' => 'ada@example.com',
            'gsmNumber' => '+905350000000',
            'identityNumber' => '11111111111',
            'billingAddress' => 'Example Street 1',
            'billingCity' => 'Istanbul',
            'billingCountry' => 'Turkey',
            'billingZipCode' => '34000',
        ], $request->toArray());

        $request->withShippingAddress($address['address'], $address['city'], $address['country'], $address['zipCode'], $address['contactName']);
        $http->pushJson(200, ['data' => ['id' => 'cus_1', 'externalId' => 'wp-42']]);
        $client->subscriptions()->customers()->upsert($request, 'cus-wp-42');

        $this->assertSame('PUT', $http->lastCall()['method']);
        $this->assertStringEndsWith('/api/v1/subscriptions/customers', $http->lastCall()['url']);
        $body = self::body($http);
        $this->assertSame('+905350000000', $body['gsmNumber']);
        $this->assertArrayNotHasKey('phone', $body);
        $this->assertArrayNotHasKey('billingContactName', $body, 'an empty contact name defaults to the full name on the gateway');
        $this->assertSame('Ada Yilmaz', $body['shippingContactName']);
        $this->assertSame('Istanbul', $body['shippingCity']);
    }
}
