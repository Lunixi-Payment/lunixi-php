<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    /** @return array{0:ApiClient,1:FakeHttpClient,2:Configuration} */
    private function make(int $maxRetries = 2, bool $seedToken = true): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration([
            'baseUrl' => 'https://gw.example.com',
            'keyId' => 'kid_1',
            'privateKey' => $keys['privateKey'],
            'maxRetries' => $maxRetries,
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();

        $store = new InMemoryTokenStore();
        if ($seedToken) {
            $store->set($config->tokenCacheKey(), 'bearer_x', 3600);
        }
        $tokens = new TokenManager($config, $signer, $http, $store);

        return [new ApiClient($config, $signer, $tokens, $http), $http, $config];
    }

    public function testGetUsesBearerOnly(): void
    {
        [$api, $http] = $this->make();
        $http->pushJson(200, ['id' => 'pi_1', 'status' => 'CAPTURED']);

        $result = $api->request('GET', '/api/v1/payments/pi_1');

        $this->assertSame('pi_1', $result['id']);
        $call = $http->lastCall();
        $this->assertSame('Bearer bearer_x', $call['headers']['Authorization']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'GET is not step-up');
        $this->assertNull($call['body']);
    }

    public function testStepUpPostAddsSignatureDigestAndIdempotency(): void
    {
        [$api, $http] = $this->make();
        $http->pushJson(200, ['intentId' => 'pi_9', 'token' => 'tok_9']);

        $result = $api->request('POST', '/api/v1/payments/intents', ['amount' => 1000, 'currency' => 'TRY'], [
            'stepUp' => true,
            'idempotencyKey' => 'idem-123',
        ]);

        $this->assertSame('pi_9', $result['intentId']);
        $h = $http->lastCall()['headers'];
        $this->assertSame('Bearer bearer_x', $h['Authorization']);
        $this->assertSame('idem-123', $h['Idempotency-Key']);
        $this->assertSame('application/json', $h['Content-Type']);
        $this->assertArrayHasKey('X-Key-Id', $h);
        $this->assertArrayHasKey('X-Signature', $h);
        $this->assertArrayHasKey('X-Date', $h);
        $this->assertArrayHasKey('X-Nonce', $h);
        $this->assertArrayHasKey('Digest', $h);
        $this->assertStringStartsWith('SHA-256=', $h['Digest']);
        $this->assertSame('{"amount":1000,"currency":"TRY"}', $http->lastCall()['body']);
    }

    /**
     * SignatureGuard recomputes the Digest over `JSON.stringify(JSON.parse(body))`.
     * V8 emits keys that are array indices ("2", "10", "2024", …) first, in
     * ascending order, and every other key in insertion order; PHP keeps
     * insertion order. Each expected body below is V8's re-serialisation of
     * what plain `json_encode` sent (measured with Node), so the test does not
     * restate the SDK's own algorithm.
     *
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function bodiesAndTheirGatewayReserialisation(): array
    {
        return [
            'integer-like metadata keys' => [
                ['metadata' => ['crmId' => 'A-77', '2024' => 'yil', '10' => 'x', '2' => 'y']],
                '{"metadata":{"2":"y","10":"x","2024":"yil","crmId":"A-77"}}',
            ],
            'an index-keyed object stays an object' => [
                ['metadata' => [1 => 'a', 0 => 'b']],
                '{"metadata":{"0":"b","1":"a"}}',
            ],
            'only array indices (0 … 2^32-2) move' => [
                ['m' => ['b' => 1, '4294967295' => 2, '4294967294' => 3, 'a' => 4, '01' => 5, '-1' => 6]],
                '{"m":{"4294967294":3,"b":1,"4294967295":2,"a":4,"01":5,"-1":6}}',
            ],
            'nested objects and objects inside lists' => [
                ['nested' => (object) ['z' => (object) ['9' => 1, 'k' => [3, 2, 1]], '5' => true], 'list' => [['b' => 1, '1' => 2]]],
                '{"nested":{"5":true,"z":{"9":1,"k":[3,2,1]}},"list":[{"1":2,"b":1}]}',
            ],
        ];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('bodiesAndTheirGatewayReserialisation')]
    public function testSignedBodyIsSentInTheOrderTheGatewayReserialisesIt(array $body, string $gatewayForm): void
    {
        [$api, $http] = $this->make();
        $http->pushJson(200, ['status' => 'success']);

        $api->request('POST', '/api/v1/payments/links', $body, ['stepUp' => true, 'idempotencyKey' => 'order-test-1']);

        $call = $http->lastCall();
        $this->assertSame($gatewayForm, $call['body']);
        $this->assertSame('SHA-256=' . base64_encode(hash('sha256', $gatewayForm, true)), $call['headers']['Digest']);
    }

    public function testClientErrorThrowsApiExceptionWithCodeAndIsNotRetried(): void
    {
        [$api, $http] = $this->make();
        $http->pushJson(400, ['code' => 'payment.intent.invalid_state', 'message' => 'bad state']);

        try {
            $api->request('POST', '/api/v1/payments/pi_1/capture', ['amount' => 100], ['stepUp' => true, 'idempotencyKey' => 'k']);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('payment.intent.invalid_state', $e->getErrorCode());
            $this->assertTrue($e->isClientError());
        }
        $this->assertSame(1, $http->callCount(), '4xx must not be retried');
    }

    public function testReAuthOnceOn401(): void
    {
        [$api, $http] = $this->make();
        $http->pushJson(401, ['code' => 'auth.expired'])               // business call → 401
            ->pushJson(200, ['access_token' => 'fresh', 'expires_in' => 1800]) // token re-fetch
            ->pushJson(200, ['ok' => true]);                            // business retry → 200

        $result = $api->request('GET', '/api/v1/payments');

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $http->callCount());
        $this->assertSame('Bearer fresh', $http->lastCall()['headers']['Authorization']);
    }

    public function testServerErrorRetriedWhenIdempotent(): void
    {
        [$api, $http] = $this->make();
        $http->pushJson(503, ['code' => 'unavailable'])->pushJson(200, ['ok' => true]);

        $result = $api->request('POST', '/api/v1/payments/pi/refund', ['amount' => 50], ['stepUp' => true, 'idempotencyKey' => 'r1']);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $http->callCount());
    }

    public function testServerErrorNotRetriedWithoutIdempotencyKey(): void
    {
        [$api, $http] = $this->make();
        $http->pushJson(503, ['code' => 'unavailable']);

        $this->expectException(ApiException::class);
        try {
            $api->request('POST', '/api/v1/payments/intents', ['amount' => 1], ['stepUp' => true]);
        } finally {
            $this->assertSame(1, $http->callCount(), 'non-idempotent POST must not be retried');
        }
    }

    public function testTransportErrorRetriedWhenIdempotent(): void
    {
        [$api, $http] = $this->make();
        $http->push(new ApiException('connection reset'))->pushJson(200, ['ok' => true]);

        $result = $api->request('GET', '/api/v1/payments');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $http->callCount());
    }

    public function testTransportErrorNotRetriedWhenNotIdempotent(): void
    {
        [$api, $http] = $this->make();
        $http->push(new ApiException('connection reset'));

        $this->expectException(ApiException::class);
        try {
            $api->request('POST', '/api/v1/payments/intents', ['amount' => 1], ['stepUp' => true]);
        } finally {
            $this->assertSame(1, $http->callCount());
        }
    }
}
