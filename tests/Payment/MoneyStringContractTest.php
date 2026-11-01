<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\BankConnect\BankConnectBalance;
use Lunixi\Sdk\Commerce\Money;
use Lunixi\Sdk\Commerce\MoneyPayload;
use Lunixi\Sdk\Commerce\OrderSnapshot;
use Lunixi\Sdk\Commerce\PaymentEvidence;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\PaymentIntent;
use Lunixi\Sdk\Payment\PromissoryNotePlan;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\WeakMoneyCaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyStringContractTest extends TestCase
{
    /** @return array{LunixiClient,FakeHttpClient} */
    private function client(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration(['baseUrl' => 'https://gateway.example', 'keyId' => 'test-key', 'privateKey' => $keys['privateKey']]);
        $tokens = new InMemoryTokenStore();
        $tokens->set($config->tokenCacheKey(), 'test-bearer', 3600);
        $http = new FakeHttpClient();
        return [new LunixiClient($config, $http, $tokens), $http];
    }

    public function testLargeFiatAmountStaysAStringThroughRequestResponseAndEvidence(): void
    {
        [$client, $http] = $this->client();
        $large = '9007199254740993';
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-large', 'token' => 'browser-token']]);
        $client->payments()->createIntent(new CreateIntentRequest($large, 'TRY', 'large-order'), 'large-create');
        self::assertStringContainsString('"amount":"9007199254740993"', $http->lastCall()['body']);
        $http->pushJson(200, ['data' => ['id' => 'pi-large', 'amount' => $large]]);
        self::assertSame($large, $client->payments()->get('pi-large')->amount());
        $snapshot = new OrderSnapshot('merchant', 'large-order', $large, 'TRY');
        $response = ['data' => ['id' => 'pi-large', 'merchantId' => 'merchant', 'orderId' => 'large-order', 'currency' => 'TRY', 'amount' => $large, 'status' => 'CAPTURED'], 'details' => ['capturedAmount' => $large, 'refundedAmount' => '0']];
        self::assertTrue(PaymentEvidence::evaluate($snapshot, 'pi-large', $response)->isPaid());
        $response['details']['refundedAmount'] = '1';
        $response['data']['status'] = 'PARTIALLY_REFUNDED';
        self::assertSame('1', PaymentEvidence::evaluate($snapshot, 'pi-large', $response)->refundedAmount);
    }

    /** @return array<string,array{mixed}> */
    public static function malformed(): array
    {
        return ['integer' => [100], 'float' => [100.0], 'negative zero' => ['-0'], 'plus' => ['+1'], 'space' => [' 1'], 'leading zero' => ['01'], 'decimal' => ['1.0'], 'exponent' => ['1e3'], 'nan' => ['NaN'], 'infinity' => ['Infinity']];
    }

    #[DataProvider('malformed')]
    public function testMalformedRawMoneyIsRejectedBeforeTransport($value): void
    {
        [$client, $http] = $this->client();
        try {
            $client->api()->request('POST', '/api/v1/payments/intents', ['amount' => $value, 'currency' => 'TRY', 'orderId' => 'o']);
            self::fail('Malformed money was sent.');
        } catch (ConfigurationException $expected) {
            self::assertSame(0, $http->callCount());
        }
    }

    public function testNumericMoneyResponseFailsBeforeRawOrTypedAccess(): void
    {
        [$client, $http] = $this->client();
        $http->pushJson(200, ['data' => ['id' => 'pi', 'amount' => 100]]);
        $this->expectException(\InvalidArgumentException::class);
        $client->payments()->get('pi');
    }

    public function testCaptureAndRefundOmitAbsentAmountButRejectExplicitZero(): void
    {
        [$client, $http] = $this->client();
        foreach (['capture', 'refund'] as $operation) {
            $http->pushJson(200, ['data' => ['id' => 'pi']]);
            $operation === 'capture' ? $client->payments()->captureRemaining('pi', 'capture-full') : $client->payments()->refundRemaining('pi', 'customer return', 'refund-full');
            $call = $http->lastCall();
            $body = json_decode($call['body'], true);
            self::assertArrayNotHasKey('amount', $body);
            self::assertSame($operation . '-full', $call['headers']['Idempotency-Key']);
            if ($operation === 'refund') { self::assertSame('customer return', $body['reason']); }
            try { $client->payments()->{$operation}('pi', '0'); self::fail('Zero mutation accepted.'); }
            catch (ConfigurationException $expected) { self::assertStringContainsString('positive', $expected->getMessage()); }
        }
        self::assertSame(2, $http->callCount());
    }

    public function testExplicitNullMutationAmountsRejectBeforeTransport(): void
    {
        [$client, $http] = $this->client();
        foreach (['capture', 'refund'] as $operation) {
            try { $client->payments()->{$operation}('pi', null); self::fail('Explicit null accepted.'); }
            catch (ConfigurationException $expected) { self::assertSame(0, $http->callCount()); }
        }
        foreach (['pi/capture', 'pi/refund', 'pi/refund-to-wallet', 'split/pi/refund'] as $operation) {
            foreach ([null, 0, 100, '0'] as $amount) {
                try {
                    $client->api()->request('POST', '/api/v1/payments/' . $operation, ['amount' => $amount]);
                    self::fail('Invalid operation amount accepted.');
                } catch (ConfigurationException $expected) { self::assertSame(0, $http->callCount()); }
            }
        }
    }

    public function testNonStrictCallerCannotCoerceMoneyBeforeAdmission(): void
    {
        [$client, $http] = $this->client();
        foreach ([100, 100.0, true, null, '0', '01'] as $amount) {
            foreach (['capture', 'refund', 'refundSplitPayment'] as $operation) {
                try {
                    WeakMoneyCaller::invoke($client->payments(), $operation, ['id-1', $amount]);
                    self::fail('Weak caller mutation accepted invalid money.');
                } catch (ConfigurationException $expected) { self::assertSame(0, $http->callCount()); }
            }
        }
        $http->pushJson(200, ['data' => ['id' => 'pi', 'amount' => '9007199254740993']]);
        WeakMoneyCaller::invoke($client->payments(), 'capture', ['pi', '9007199254740993', 'weak-large']);
        self::assertSame('9007199254740993', json_decode($http->lastCall()['body'], true)['amount']);
        self::assertSame(1, $http->callCount());
    }

    public function testNonStrictRequestConstructorsAndFactoriesRejectNumericMoney(): void
    {
        $card = new \Lunixi\Sdk\Payment\CardDetails(['cardNumber' => '5400000000000004']);
        $buyer = new \Lunixi\Sdk\Payment\Buyer([
            'name' => 'Ada', 'surname' => 'Yilmaz', 'identityNumber' => '11111111111',
            'email' => 'ada@example.com', 'gsmNumber' => '+905350000000', 'city' => 'Istanbul',
            'country' => 'TR', 'zipCode' => '34000', 'ip' => '127.0.0.1',
        ]);
        $address = new \Lunixi\Sdk\Payment\Address([
            'address' => 'Example Street 1', 'zipCode' => '34000', 'contactName' => 'Ada Yilmaz',
            'city' => 'Istanbul', 'country' => 'TR',
        ]);
        foreach ([100, 100.0, true, null] as $amount) {
            $cases = [
                [CreateIntentRequest::class, [$amount, 'TRY', 'o']],
                [\Lunixi\Sdk\Payment\DirectMethodPaymentRequest::class, [$amount, 'TRY', 'o']],
                [\Lunixi\Sdk\Payment\DirectPaymentRequest::class, [$amount, 'TRY', 'o', $card, $buyer, $address, []]],
                [\Lunixi\Sdk\Payment\SplitPaymentRequest::class, [$amount, 'TRY', 'owner']],
                [\Lunixi\Sdk\Marketplace\MarketplaceSeller::class, ['seller', $amount]],
                [\Lunixi\Sdk\Subscription\CreatePlanRequest::class, ['product', 'code', 'Name', $amount, 'TRY', new \Lunixi\Sdk\Subscription\BillingRule('MONTHLY')]],
                [\Lunixi\Sdk\Payment\PaymentLinkItem::class, ['Item', $amount]],
                [OrderSnapshot::class, ['merchant', 'order', $amount, 'TRY']],
            ];
            foreach ($cases as [$class, $arguments]) {
                try { WeakMoneyCaller::construct($class, $arguments); self::fail($class . ' coerced invalid money.'); }
                catch (ConfigurationException | \InvalidArgumentException $expected) { self::assertStringContainsString('string', $expected->getMessage()); }
            }
            try {
                WeakMoneyCaller::invokeStatic(\Lunixi\Sdk\Payment\CreatePaymentLinkRequest::class, 'fixed', ['SINGLE_USE', $amount, 'TRY', 'Link']);
                self::fail('Link factory coerced invalid money.');
            } catch (ConfigurationException $expected) { self::assertStringContainsString('string', $expected->getMessage()); }
            foreach (['add', 'subtract', 'compare', 'minimum', 'difference'] as $method) {
                try { WeakMoneyCaller::invokeStatic(Money::class, $method, [$amount, '1']); self::fail('Money helper coerced invalid money.'); }
                catch (\InvalidArgumentException $expected) { self::assertStringContainsString('string', $expected->getMessage()); }
            }
        }
    }

    public function testCurrencyExponentsAndSignedBalanceStayExact(): void
    {
        foreach ([['JPY', '9007199254740993', '9007199254740993'], ['TRY', '90071992547409.93', '9007199254740993'], ['KWD', '9007199254740.993', '9007199254740993']] as [$currency, $decimal, $minor]) {
            self::assertSame($minor, Money::minor($decimal, Money::exponent($currency)));
            self::assertSame($decimal, Money::decimal($minor, Money::exponent($currency)));
        }
        self::assertSame('-9007199254740993', (new BankConnectBalance(['balanceMinor' => '-9007199254740993']))->balanceMinor());
        self::assertSame('9223372036854775807', Money::integer('9223372036854775807'));
        self::assertSame('100000000000000000000000000000000', Money::canonical('100000000000000000000000000000000'));
        $this->expectException(\InvalidArgumentException::class);
        Money::integer('9223372036854775808');
    }

    public function testSchedulesAndAllocationUseExactStringArithmetic(): void
    {
        $schedule = PromissoryNotePlan::requireSchedule([['amount' => '9007199254740992', 'dueDate' => '2030-01-01'], ['amount' => '1', 'dueDate' => '2030-02-01']]);
        PromissoryNotePlan::assertMatchesAmount($schedule, '9007199254740993');
        self::assertSame('9007199254740993', Money::add('9007199254740992', '1'));
        self::assertSame('1', Money::subtract('9007199254740993', '9007199254740992'));
        self::assertSame('4503599627370496', Money::prorate('9007199254740993', '1', '2'));
        self::assertSame(-1, Money::compare('9007199254740992', '9007199254740993'));
        self::assertSame('0', (new BasketItem(['id' => 'free', 'price' => '0', 'name' => 'Free', 'category1' => 'Gift', 'itemType' => 'VIRTUAL']))->toArray()['price']);
    }

    public function testValidationDoesNotInterpretMerchantOrProviderCustomData(): void
    {
        $opaque = ['metadata' => ['amount' => 1], 'providerPayload' => ['amount' => 2], 'settings' => ['amount' => 3], 'schema' => ['amount' => 4], 'inputFields' => [['amount' => 5]]];
        MoneyPayload::validate(['amount' => '9007199254740993'] + $opaque);
        self::assertFalse(MoneyPayload::supports('/api/v1/identity/analyze'));
        self::assertSame($opaque['metadata'], (new PaymentIntent(['amount' => '1'] + $opaque))->raw()['metadata']);
    }

    public function testDecodedObjectMoneyValidationPreservesBytesAndRejectsNumbers(): void
    {
        $payload = json_decode('{"data":{"amount":"9007199254740993","items":[{"price":"0"}]},"metadata":{"amount":1}}');
        $before = json_encode($payload);
        MoneyPayload::validate($payload);
        self::assertSame($before, json_encode($payload));

        $payload->data->items[0]->price = 1;
        $this->expectException(\InvalidArgumentException::class);
        MoneyPayload::validate($payload);
    }
}
