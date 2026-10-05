<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Auth;

use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\AuthenticationException;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class TokenManagerTest extends TestCase
{
    private function manager(FakeHttpClient $http): TokenManager
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration([
            'baseUrl' => 'https://gw.example.com',
            'keyId' => 'kid_1',
            'privateKey' => $keys['privateKey'],
        ]);

        return new TokenManager($config, new Ed25519Signer($keys['privateKey']), $http);
    }

    public function testFetchesTokenWithSignedBodylessRequestAndCaches(): void
    {
        $http = (new FakeHttpClient())->pushJson(200, ['access_token' => 'at_x', 'expires_in' => 1800]);
        $manager = $this->manager($http);

        $this->assertSame('at_x', $manager->getToken());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/auth/token', $call['url']);
        $this->assertNull($call['body'], 'token request is bodyless');
        $this->assertArrayHasKey('X-Key-Id', $call['headers']);
        $this->assertArrayHasKey('X-Date', $call['headers']);
        $this->assertArrayHasKey('X-Nonce', $call['headers']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertArrayNotHasKey('Authorization', $call['headers'], 'token fetch carries no bearer');

        // Second call is served from cache (no new HTTP request).
        $this->assertSame('at_x', $manager->getToken());
        $this->assertSame(1, $http->callCount());
    }

    public function testForceRefreshRefetches(): void
    {
        $http = (new FakeHttpClient())
            ->pushJson(200, ['access_token' => 'at_1', 'expires_in' => 1800])
            ->pushJson(200, ['access_token' => 'at_2', 'expires_in' => 1800]);
        $manager = $this->manager($http);

        $this->assertSame('at_1', $manager->getToken());
        $this->assertSame('at_2', $manager->getToken(true));
        $this->assertSame(2, $http->callCount());
    }

    public function testInvalidateForcesRefetch(): void
    {
        $http = (new FakeHttpClient())
            ->pushJson(200, ['access_token' => 'at_1', 'expires_in' => 1800])
            ->pushJson(200, ['access_token' => 'at_2', 'expires_in' => 1800]);
        $manager = $this->manager($http);

        $manager->getToken();
        $manager->invalidate();
        $this->assertSame('at_2', $manager->getToken());
        $this->assertSame(2, $http->callCount());
    }

    public function testNonSuccessThrowsAuthenticationException(): void
    {
        $http = (new FakeHttpClient())->pushJson(401, ['code' => 'auth.invalid_signature']);

        $this->expectException(AuthenticationException::class);
        $this->manager($http)->getToken();
    }

    public function testMissingAccessTokenThrows(): void
    {
        $http = (new FakeHttpClient())->pushJson(200, ['token_type' => 'Bearer']);

        $this->expectException(AuthenticationException::class);
        $this->manager($http)->getToken();
    }
}
