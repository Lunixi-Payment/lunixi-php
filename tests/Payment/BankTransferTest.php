<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Payment\BankTransferEvents;
use Lunixi\Sdk\Payment\BankTransferException;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\PaymentActionType;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\OrderDetailsFixture;
use PHPUnit\Framework\TestCase;

/**
 * Para Transferi (BANK_TRANSFER): the bankTransfer block on POST /payments/direct/payment,
 * the DISPLAY transfer instruction and the bank transfer exception endpoints.
 */
final class BankTransferTest extends TestCase
{
    private const PAYMENT_ID = 'a71c2e4b-0f6d-4b8a-9c1e-2d3f4a5b6c7d';
    private const EXCEPTION_ID = '3d2b9a1c-7e4f-4b2a-8c6d-1e2f3a4b5c6d';

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

    public function testBankTransferIsADirectPaymentBlockWithoutProviderCode(): void
    {
        $this->assertContains('bankTransfer', PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS);
        $this->assertSame('bankTransfer', PaymentMethod::blockName(PaymentMethod::BANK_TRANSFER));
        $this->assertSame('9999', PaymentMethod::BANK_TRANSFER_OTHER_BANKS_CODE);
        $this->assertSame('payment.bank_transfer.late_arrival', BankTransferEvents::LATE_ARRIVAL);

        $body = (new DirectMethodPaymentRequest('125000', 'TRY', 'ORDER-2041'))
            ->withMethodFields(PaymentMethod::BANK_TRANSFER, null, ['bankCode' => '0062'])
            ->toArray();
        $this->assertSame(['bankCode' => '0062'], $body['bankTransfer']);
        $this->assertSame('BANK_TRANSFER', $body['paymentMethod']);

        $this->expectException(ConfigurationException::class);
        (new DirectMethodPaymentRequest('125000', 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::BANK_TRANSFER, 'ANY', ['bankCode' => '0062']);
    }

    public function testDirectPaymentReturnsTheTransferInstruction(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(202, [
            'status' => 'partial',
            'code' => 'CUSTOMER_ACTION_REQUIRED',
            'statusCode' => 202,
            'data' => [
                'paymentId' => self::PAYMENT_ID,
                'orderId' => 'ORDER-2041',
                'status' => 'PROCESSING',
                'paymentMethod' => 'BANK_TRANSFER',
                'action' => [
                    'id' => 'act_1',
                    'type' => 'DISPLAY',
                    'expiresAt' => '2026-11-18T20:59:59.999Z',
                    'bankTransfer' => [
                        'bankCode' => '0062', 'bankName' => 'Garanti BBVA', 'holderName' => 'ÖRNEK TİCARET A.Ş.',
                        'iban' => 'TR000006200000000000000001', 'amountMinor' => '125000', 'currency' => 'TRY',
                        'paymentCode' => 'LNXK7Q3P9', 'expiresAt' => '2026-11-18T20:59:59.999Z', 'otherBank' => false,
                    ],
                ],
            ],
        ]);
        $request = OrderDetailsFixture::complete((new DirectMethodPaymentRequest('125000', 'TRY', 'ORDER-2041'))
            ->withMethodFields(PaymentMethod::BANK_TRANSFER, null, ['bankCode' => '0062']), '125000');

        $result = (new PaymentClient($api))->directMethodPayment($request, 'pay:ORDER-2041');

        $this->assertSame(['bankCode' => '0062'], json_decode((string) $http->lastCall()['body'], true)['bankTransfer']);
        $action = $result->action();
        $this->assertNotNull($action);
        $this->assertSame(PaymentActionType::DISPLAY, $action->type());
        $this->assertSame('LNXK7Q3P9', $action->bankTransfer()['paymentCode']);
    }

    public function testExceptionEndpoints(): void
    {
        [$api, $http] = $this->api();
        $client = new PaymentClient($api);

        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'statusCode' => 200, 'data' => ['items' => [['id' => self::EXCEPTION_ID]]]]);
        $items = $client->listBankTransferExceptions('LIVE', BankTransferException::STATUS_PENDING_DECISION, 20);
        $this->assertSame(self::EXCEPTION_ID, $items[0]['id']);
        $url = parse_url($http->lastCall()['url']);
        $this->assertSame('/api/v1/payments/bank-transfer/exceptions', $url['path']);
        parse_str($url['query'] ?? '', $query);
        $this->assertSame(['environment' => 'LIVE', 'status' => 'PENDING_DECISION', 'limit' => '20'], $query);
        $this->assertArrayHasKey('X-Signature', $http->lastCall()['headers']);

        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'statusCode' => 200, 'data' => ['id' => self::EXCEPTION_ID, 'status' => 'ACCEPTED']]);
        $client->acceptBankTransferException(self::EXCEPTION_ID);
        $this->assertSame('POST', $http->lastCall()['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/bank-transfer/exceptions/' . self::EXCEPTION_ID . '/accept', $http->lastCall()['url']);

        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'statusCode' => 200, 'data' => ['id' => self::EXCEPTION_ID, 'status' => 'REFUNDED']]);
        $client->markBankTransferExceptionRefunded(self::EXCEPTION_ID);
        $this->assertSame('https://gw.example.com/api/v1/payments/bank-transfer/exceptions/' . self::EXCEPTION_ID . '/refunded', $http->lastCall()['url']);
    }

    public function testInvalidFiltersAndIdsAreRefusedBeforeSending(): void
    {
        [$api, $http] = $this->api();
        $client = new PaymentClient($api);
        $attempts = [
            static fn () => $client->listBankTransferExceptions('live'),
            static fn () => $client->listBankTransferExceptions(null, 'OPEN'),
            static fn () => $client->listBankTransferExceptions(null, null, 0),
            static fn () => $client->listBankTransferExceptions(null, null, 201),
            static fn () => $client->acceptBankTransferException('not-a-uuid'),
            static fn () => $client->markBankTransferExceptionRefunded(''),
        ];
        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
                $this->fail('attempt ' . $index . ' should be refused');
            } catch (ConfigurationException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        $this->assertSame(0, $http->callCount());
    }
}
