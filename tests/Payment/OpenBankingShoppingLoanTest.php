<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\PaymentActionType;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\ProductCategory;
use Lunixi\Sdk\Payment\SplitPaymentRequest;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\OrderDetailsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Open banking (OPEN_BANKING), shopping loan (SHOPPING_LOAN), meal card (MEAL_CARD)
 * and redirect wallets on POST /payments/direct/payment. The blocks follow the
 * gateway's MethodPaymentSelectionDto (openBanking, shoppingLoan, mealCard).
 */
final class OpenBankingShoppingLoanTest extends TestCase
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

    /** @return BasketItem[] */
    private static function basket(int $count, string $price = '1000'): array
    {
        $items = [];
        for ($index = 0; $index < $count; $index++) {
            $items[] = new BasketItem([
                'id' => 'i' . $index, 'price' => $price, 'name' => 'Telefon', 'category1' => 'Elektronik',
                'itemType' => BasketItem::TYPE_PHYSICAL, 'productCategory' => ProductCategory::MOBILE_PHONE,
            ]);
        }

        return $items;
    }

    private static function shoppingLoan(string $amount): DirectMethodPaymentRequest
    {
        return (new DirectMethodPaymentRequest($amount, 'TRY', 'ORD-SL-1'))
            ->withMethodFields(PaymentMethod::SHOPPING_LOAN, 'COMPAY', ['bankCode' => '0062']);
    }

    public function testTheBlockListCarriesMealCardOpenBankingAndShoppingLoan(): void
    {
        $this->assertSame(
            ['mobileBilling', 'wallet', 'promissoryNote', 'mealCard', 'openBanking', 'shoppingLoan', 'bankTransfer'],
            PaymentMethod::METHOD_BLOCK_FIELDS
        );
        foreach (['mealCard', 'openBanking', 'shoppingLoan'] as $block) {
            $this->assertContains($block, PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS);
        }
        $this->assertSame(100, PaymentMethod::SHOPPING_LOAN_MAX_BASKET_ITEMS);
        $this->assertSame('openBanking', PaymentMethod::blockName(PaymentMethod::OPEN_BANKING));
        $this->assertSame('shoppingLoan', PaymentMethod::blockName(PaymentMethod::SHOPPING_LOAN));
        $this->assertSame('mealCard', PaymentMethod::blockName(PaymentMethod::MEAL_CARD));
    }

    public function testOpenBankingSendsTheChosenBankInItsBlock(): void
    {
        $body = (new DirectMethodPaymentRequest('5000', 'TRY', 'ORD-OB-1'))
            ->withMethodFields(PaymentMethod::OPEN_BANKING, 'COMPAY', ['bankCode' => '0062'])
            ->withCallbackUrl('https://merchant.example.com/payment/callback')
            ->toArray();

        $this->assertSame([
            'paymentMethod' => 'OPEN_BANKING',
            'amount' => '5000',
            'currency' => 'TRY',
            'orderId' => 'ORD-OB-1',
            'callbackUrl' => 'https://merchant.example.com/payment/callback',
            'openBanking' => ['providerCode' => 'COMPAY', 'bankCode' => '0062'],
        ], $body);
        foreach (array_keys($body) as $key) {
            $this->assertContains($key, PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS, $key);
        }
    }

    public function testShoppingLoanSendsItsBlockWithTheBasket(): void
    {
        $body = self::shoppingLoan('2000')->withBasketItems(self::basket(2))->toArray();

        $this->assertSame(['providerCode' => 'COMPAY', 'bankCode' => '0062'], $body['shoppingLoan']);
        $this->assertCount(2, $body['basketItems']);
        $this->assertSame('MOBILE_PHONE', $body['basketItems'][0]['productCategory']);
        $this->assertArrayNotHasKey('openBanking', $body);
    }

    /** @return array<string,array{0:int}> */
    public static function badBasketSizes(): array
    {
        return ['no basket' => [-1], 'empty basket' => [0], '101 items' => [101]];
    }

    #[DataProvider('badBasketSizes')]
    public function testAShoppingLoanNeedsOneToOneHundredBasketItems(int $count): void
    {
        $request = self::shoppingLoan('5050');
        if ($count >= 0) {
            $request->withBasketItems(self::basket($count, '50'));
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($count > 100 ? 'at most 100 basket items' : 'requires basketItems');
        $request->toArray();
    }

    public function testAHundredItemBasketAndOtherMethodsWithoutABasketPass(): void
    {
        $this->assertCount(100, self::shoppingLoan('5000')->withBasketItems(self::basket(100, '50'))->toArray()['basketItems']);
        $body = (new DirectMethodPaymentRequest('5000', 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::OPEN_BANKING, 'COMPAY', ['bankCode' => '0062'])
            ->toArray();
        $this->assertArrayNotHasKey('basketItems', $body);
    }

    public function testMealCardSendsTheMealCardBlock(): void
    {
        $body = (new DirectMethodPaymentRequest('5000', 'TRY', 'ORD-MC-1'))
            ->withMethodFields(PaymentMethod::MEAL_CARD, 'METROPOL')
            ->toArray();

        $this->assertSame(['providerCode' => 'METROPOL'], $body['mealCard']);
    }

    public function testARedirectWalletIsAWalletBlockWithItsProviderCodeOnly(): void
    {
        foreach (['COMPAY_PAYCELL_WALLET', 'COMPAY_FASTPAY', 'COMPAY_PASSO_WALLET'] as $providerCode) {
            $body = (new DirectMethodPaymentRequest('5000', 'TRY', 'ORD-W-1'))
                ->withMethodFields(PaymentMethod::WALLET, $providerCode)
                ->toArray();
            $this->assertSame(['providerCode' => $providerCode], $body['wallet']);
        }
    }

    public function testOpenBankingIsSignedAndKeyedAndTheRedirectUrlIsRead(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(202, [
            'status' => 'partial',
            'code' => 'payment.action.required',
            'statusCode' => 202,
            'data' => [
                'paymentId' => self::PAYMENT_ID,
                'orderId' => 'ORD-OB-1',
                'status' => 'INITIATED',
                'paymentMethod' => 'OPEN_BANKING',
                'providerCode' => 'COMPAY',
                'action' => [
                    'id' => 'act_1',
                    'type' => 'REDIRECT',
                    'expiresAt' => '2026-11-12T10:05:00.000Z',
                    'redirect' => ['url' => 'https://pay.example.com/launch/abc'],
                ],
            ],
        ]);
        $request = OrderDetailsFixture::complete((new DirectMethodPaymentRequest('5000', 'TRY', 'ORD-OB-1'))
            ->withMethodFields(PaymentMethod::OPEN_BANKING, 'COMPAY', ['bankCode' => '0062'])
            ->withCallbackUrl('https://merchant.example.com/payment/callback'), '5000');

        $result = (new PaymentClient($api))->directMethodPayment($request, 'pay:ORD-OB-1');

        $call = $http->lastCall();
        $this->assertSame('https://gw.example.com/api/v1/payments/direct/payment', $call['url']);
        $this->assertSame('pay:ORD-OB-1', $call['headers']['Idempotency-Key']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertSame(['providerCode' => 'COMPAY', 'bankCode' => '0062'], json_decode((string) $call['body'], true)['openBanking']);
        $action = $result->action();
        $this->assertNotNull($action);
        $this->assertSame(PaymentActionType::REDIRECT, $action->type());
        $this->assertSame('https://pay.example.com/launch/abc', $action->redirectUrl());
    }

    /** @return array<string,array{0:string}> */
    public static function methodsThatAreNotSplitLegs(): array
    {
        return [
            'open banking' => [PaymentMethod::OPEN_BANKING],
            'shopping loan' => [PaymentMethod::SHOPPING_LOAN],
            'meal card' => [PaymentMethod::MEAL_CARD],
        ];
    }

    #[DataProvider('methodsThatAreNotSplitLegs')]
    public function testTheNewMethodsCannotBeSplitPaymentLegs(string $method): void
    {
        $this->assertSame(['mobileBilling', 'wallet', 'promissoryNote'], SplitPaymentRequest::LEG_BLOCK_FIELDS);
        $this->assertNotContains(PaymentMethod::blockName($method), SplitPaymentRequest::LEG_FIELDS);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be a split payment leg');
        (new SplitPaymentRequest('10000', 'TRY', 'cuk_1'))->addMethodLeg($method, 'COMPAY', '10000', ['bankCode' => '0062']);
    }
}
