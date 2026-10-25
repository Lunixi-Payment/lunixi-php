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
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Amount contract of the payment bodies (gateway K17): `amount` is the only
 * amount field of POST /payments/intents, /payments/marketplace/intents,
 * /payments/marketplace/2d|3d and /payments/direct/payment (card included).
 * `price` and `paidPrice` were removed; the gateway answers them with 400
 * `payment.amount.field_removed`, so the SDK refuses them before anything is
 * sent. A basket item keeps its own `price`. DirectPaymentRequest is the base
 * body of the marketplace 2D/3D charge.
 */
final class AmountContractTest extends TestCase
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

    private static function card(): CardDetails
    {
        return new CardDetails([
            'cardHolderName' => 'Ada Yilmaz',
            'cardNumber' => '5400000000000004',
            'expireMonth' => '12',
            'expireYear' => '28',
            'cvcNumber' => '123',
        ]);
    }

    private static function direct(int $amount = 10050): DirectPaymentRequest
    {
        return new DirectPaymentRequest(
            $amount,
            'TRY',
            'ORD-AMT-1',
            self::card(),
            new Buyer([
                'name' => 'Ada', 'surname' => 'Yilmaz', 'identityNumber' => '11111111111',
                'email' => 'ada@example.com', 'gsmNumber' => '+905350000000', 'city' => 'Istanbul',
                'country' => 'TR', 'zipCode' => '34000', 'ip' => '127.0.0.1',
            ]),
            new Address([
                'address' => 'Example Street 1', 'zipCode' => '34000', 'contactName' => 'Ada Yilmaz',
                'city' => 'Istanbul', 'country' => 'TR',
            ]),
            // The item is valid for any amount: an invalid `amount` must be refused by the request itself.
            [self::basketItem(max(0, $amount))]
        );
    }

    private static function basketItem(int $price): BasketItem
    {
        return new BasketItem(['id' => 'SKU-001', 'price' => $price, 'name' => 'Demo Product', 'category1' => 'Demo', 'itemType' => BasketItem::TYPE_PHYSICAL]);
    }

    /** @param array<string,mixed> $body */
    private static function assertNoRemovedAmountField(array $body, string $label): void
    {
        foreach (PaymentClient::REMOVED_AMOUNT_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $body, $label . ': ' . $field);
        }
    }

    // --- Builders ------------------------------------------------------------------

    public function testEveryBuilderSendsAmountAndNeverPriceOrPaidPrice(): void
    {
        $bodies = [
            'intent' => (new CreateIntentRequest(10050, 'TRY', 'ORD-AMT-1'))
                ->withBasketItems([self::basketItem(10050)])
                ->toArray(),
            'direct' => self::direct()->toArray(),
            'method card' => (new DirectMethodPaymentRequest(10050, 'TRY', 'ORD-AMT-1'))->withCard(self::card())->toArray(),
            'method mobile billing' => (new DirectMethodPaymentRequest(10050, 'TRY', 'ORD-AMT-1'))
                ->withMethodFields(PaymentMethod::MOBILE_BILLING, null, ['gsmNumber' => '+905321234567'])
                ->toArray(),
        ];

        foreach ($bodies as $label => $body) {
            $this->assertSame(10050, $body['amount'], $label);
            self::assertNoRemovedAmountField($body, $label);
        }
        $this->assertSame(10050, $bodies['intent']['basketItems'][0]['price'], 'a basket item keeps its own price');
        $this->assertSame(10050, $bodies['direct']['basketItems'][0]['price'], 'a basket item keeps its own price');
    }

    public function testTheRemovedAmountSettersAreGone(): void
    {
        foreach ([CreateIntentRequest::class, DirectPaymentRequest::class, DirectMethodPaymentRequest::class] as $class) {
            foreach (['withPrice', 'withPaidPrice'] as $method) {
                $this->assertFalse(method_exists($class, $method), $class . '::' . $method);
            }
        }
        // The intent has no installment field any more (gateway: 400); the buyer picks it on the form.
        $this->assertFalse(method_exists(CreateIntentRequest::class, 'withInstallment'));
    }

    /** @return array<string,array{0:int}> */
    public static function nonPositiveAmounts(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'large negative' => [PHP_INT_MIN]];
    }

    #[DataProvider('nonPositiveAmounts')]
    public function testAmountIsAtLeastOneMinorUnitOnEveryBuilder(int $amount): void
    {
        $builders = [
            'CreateIntentRequest' => static fn () => new CreateIntentRequest($amount, 'TRY', 'O'),
            'DirectPaymentRequest' => static fn () => self::direct($amount),
            'DirectMethodPaymentRequest' => static fn () => new DirectMethodPaymentRequest($amount, 'TRY', 'O'),
        ];
        foreach ($builders as $name => $build) {
            try {
                $build();
                $this->fail($name . ' accepted amount ' . $amount);
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString("'amount'", $e->getMessage(), $name);
            }
        }
        $this->assertSame(1, (new CreateIntentRequest(1, 'TRY', 'O'))->toArray()['amount'], 'one minor unit is the smallest amount');
    }

    public function testMarketplaceChargeBaseCarriesInstallmentAndTheQuoteToken(): void
    {
        $body = self::direct(15000)->withInstallment(3, 'iq_1')->toArray();

        $this->assertSame(15000, $body['amount'], 'amount stays the basket total; the system adds the installment fee');
        $this->assertSame(3, $body['installment']);
        $this->assertSame('iq_1', $body['installmentQuoteToken']);

        $withoutQuote = self::direct()->withInstallment(2)->toArray();
        $this->assertSame(2, $withoutQuote['installment']);
        $this->assertArrayNotHasKey('installmentQuoteToken', $withoutQuote);
    }

    public function testTheDirectMethodFieldListCarriesAmountOnly(): void
    {
        $this->assertSame(['price', 'paidPrice'], PaymentClient::REMOVED_AMOUNT_FIELDS);
        $this->assertContains('amount', PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS);
        $this->assertSame([], array_values(array_intersect(PaymentClient::REMOVED_AMOUNT_FIELDS, PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS)));
    }

    /**
     * The field list's single source is the Node SDK;
     * the PHP list is the same set.
     */
    public function testTheDirectMethodFieldListMatchesTheNodeSdk(): void
    {
        $source = dirname(__DIR__, 3) . '/node/src/payment/payment-method-values.js';
        if (!is_dir(dirname(__DIR__, 3) . '/node')) {
            $this->markTestSkipped('Parity with the Node SDK is checked in the Lunixi monorepo only.');
        }
        $this->assertFileExists($source);
        $text = (string) file_get_contents($source);

        $fields = [];
        foreach (['DIRECT_METHOD_PAYMENT_FIELDS_WITHOUT_BLOCKS = [', "METHOD_BLOCK_FIELDS = Object.freeze(["] as $marker) {
            $start = strpos($text, $marker);
            $this->assertNotFalse($start, $marker);
            $end = strpos($text, ']', $start + strlen($marker));
            preg_match_all("/'([A-Za-z0-9]+)'/", substr($text, $start, $end - $start), $matches);
            $this->assertNotSame([], $matches[1], $marker);
            $fields = array_merge($fields, $matches[1]);
        }

        $php = PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS;
        sort($fields);
        sort($php);
        $this->assertSame($fields, $php);
    }

    // --- Raw arrays are refused before sending -------------------------------------------

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function removedFields(): array
    {
        return [
            'price' => [['price' => 10050]],
            'paidPrice' => [['paidPrice' => 10050]],
            'both' => [['price' => 10050, 'paidPrice' => 10050]],
            'null paidPrice' => [['paidPrice' => null]],
            'text price' => [['price' => '10050']],
        ];
    }

    /** @param array<string,mixed> $extra */
    #[DataProvider('removedFields')]
    public function testARawArrayWithARemovedAmountFieldIsRefusedBeforeSending(array $extra): void
    {
        [$api, $http] = $this->api();
        $payments = new PaymentClient($api);
        $marketplace = new MarketplaceClient($api);
        $sellers = [['subDealerId' => 'd_1', 'amountMinor' => '10050']];
        $intent = (new CreateIntentRequest(10050, 'TRY', 'ORD-AMT-1'))->toArray() + ['sellers' => $sellers];
        $direct = self::direct()->toArray();

        $sends = [
            'createMarketplaceIntent' => static fn () => $payments->createMarketplaceIntent(array_merge($intent, $extra), 'k-mp'),
            'chargeMarketplaceCard' => static fn () => $marketplace->chargeMarketplaceCard(array_merge($direct, ['sellers' => $sellers], $extra), true, 'k-mp-3d'),
        ];
        foreach ($sends as $operation => $send) {
            try {
                $send();
                $this->fail($operation . ' sent a removed amount field');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('removed', $e->getMessage(), $operation);
                $this->assertStringContainsString('`amount`', $e->getMessage(), $operation);
            }
        }
        $this->assertSame(0, $http->callCount(), 'nothing reached the gateway');
    }

    public function testTheSameRawArraysWithoutTheRemovedFieldsAreSent(): void
    {
        [$api, $http] = $this->api();
        $payments = new PaymentClient($api);
        $marketplace = new MarketplaceClient($api);
        $sellers = [['subDealerId' => 'd_1', 'amountMinor' => '10050']];
        for ($i = 0; $i < 2; $i++) {
            $http->pushJson(200, ['success' => true, 'data' => ['paymentId' => 'pi_' . $i]]);
        }

        $payments->createMarketplaceIntent((new CreateIntentRequest(10050, 'TRY', 'ORD-AMT-1'))->toArray() + ['sellers' => $sellers], 'k-mp');
        $marketplace->chargeMarketplaceCard2d(self::direct(), [new MarketplaceSeller('d_1', 10050)], MarketplaceClient::POLICY_ALL_OR_NOTHING, 'k-mp-2d');

        $paths = array_map(static fn (array $call): string => (string) parse_url($call['url'], PHP_URL_PATH), $http->calls);
        $this->assertSame([
            '/api/v1/payments/marketplace/intents',
            '/api/v1/payments/marketplace/2d',
        ], $paths);
        foreach ($http->calls as $call) {
            $body = json_decode((string) $call['body'], true);
            $this->assertSame(10050, $body['amount']);
            self::assertNoRemovedAmountField($body, (string) $call['url']);
        }
        $marketplaceCharge = json_decode((string) $http->lastCall()['body'], true);
        $this->assertSame([['subDealerId' => 'd_1', 'amountMinor' => '10050']], $marketplaceCharge['sellers']);
    }
}
