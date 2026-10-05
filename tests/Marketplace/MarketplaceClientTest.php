<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Marketplace;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Marketplace\CreateDealerRequest;
use Lunixi\Sdk\Marketplace\DealerStatus;
use Lunixi\Sdk\Marketplace\MarketplaceClient;
use Lunixi\Sdk\Marketplace\MarketplaceSeller;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class MarketplaceClientTest extends TestCase
{
    /** @return array{0:MarketplaceClient,1:FakeHttpClient} */
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

        return [new MarketplaceClient(new ApiClient($config, $signer, $tokens, $http)), $http];
    }

    public function testCreateCheckoutIntentSendsSplitWithStepUp(): void
    {
        [$mp, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'paymentId' => 'pi_1', 'token' => 'tok_1', 'checkoutFormContent' => '<script></script>']);

        $req = new CreateIntentRequest(100000, 'TRY', 'WC-7');
        $sellers = [
            new MarketplaceSeller('d_alice', 60000),
            (new MarketplaceSeller('d_bob', 40000))->withScenario(['type' => 'DYNAMIC_RATE', 'ratePercent' => '5']),
        ];
        $intent = $mp->createCheckoutIntent($req, $sellers, MarketplaceClient::POLICY_ALL_OR_NOTHING, 'idem-mp-1');

        $this->assertSame('pi_1', $intent->paymentId());
        $this->assertSame('tok_1', $intent->token());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/payments/marketplace/intents', $call['url']);
        $this->assertArrayHasKey('X-Signature', $call['headers'], 'marketplace checkout init is step-up');
        $this->assertSame('idem-mp-1', $call['headers']['Idempotency-Key']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame(100000, $body['amount']);
        $this->assertSame('ALL_OR_NOTHING', $body['multiSellerPolicy']);
        $this->assertCount(2, $body['sellers']);
        $this->assertSame('d_alice', $body['sellers'][0]['subDealerId']);
        $this->assertSame('60000', $body['sellers'][0]['amountMinor']); // minor units as string
        $this->assertSame('DYNAMIC_RATE', $body['sellers'][1]['scenario']['type']);
    }

    public function testCreateDealerAndOnboardingToken(): void
    {
        [$mp, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'data' => ['id' => 'd_1', 'legalName' => 'Acme', 'status' => 'DRAFT']]);
        $dealer = $mp->createDealer((new CreateDealerRequest('Acme'))->withTaxId('123')->withExternalRef('42'));
        $this->assertSame('d_1', $dealer->id());
        $this->assertSame('DRAFT', $dealer->status());
        $body = json_decode((string) $http->lastCall()['body'], true);
        $this->assertSame('Acme', $body['legalName']);
        $this->assertSame('42', $body['externalRef']);
        $this->assertArrayNotHasKey('X-Signature', $http->lastCall()['headers'], 'dealer mgmt is bearer-only');

        $http->pushJson(201, ['success' => true, 'token' => 'mp_xyz', 'url' => 'https://altbayi.lunixi.com/m/mp_xyz']);
        $link = $mp->onboardingToken('d_1');
        $this->assertSame('mp_xyz', $link->token());
        $this->assertSame('https://altbayi.lunixi.com/m/mp_xyz', $link->url());
        $this->assertStringEndsWith('/api/v1/marketplace/dealers/d_1/onboarding-token', $http->lastCall()['url']);
    }

    public function testListDealersAndStatusUpdate(): void
    {
        [$mp, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [['id' => 'd_1', 'status' => 'ACTIVE'], ['id' => 'd_2', 'status' => 'DRAFT']]]);
        $dealers = $mp->listDealers(['status' => 'ACTIVE']);
        $this->assertCount(2, $dealers);
        $this->assertTrue($dealers[0]->isActive());
        $this->assertStringContainsString('status=ACTIVE', $http->lastCall()['url']);

        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'd_2', 'status' => 'SUSPENDED']]);
        $updated = $mp->updateDealerStatus('d_2', DealerStatus::SUSPENDED);
        $this->assertSame('SUSPENDED', $updated->status());
        $this->assertSame('PUT', $http->lastCall()['method']);
        $this->assertSame('SUSPENDED', json_decode((string) $http->lastCall()['body'], true)['status']);
    }

    /**
     * 🔴 REGRESYON KİLİDİ — gateway'in GERÇEK bayi listesi zarfı.
     *
     * `GET /api/v1/marketplace/dealers` `{items,total}` döndürüyor (marketplace
     * `call()` ham payload'ı geçiyor). Eski kod `$response['data']`'yı bir BAYİ
     * DİZİSİ sanıyordu → metot DAİMA BOŞ dizi dönüyordu ve WordPress
     * eklentisinin bayi ekranı boş kalıyordu. Yukarıdaki test `data`'nın düz
     * liste olduğu ESKİ şekli kullandığı için bu defekti göremiyordu.
     */
    public function testListDealersParsesItemsEnvelope(): void
    {
        [$mp, $http] = $this->make();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => [
            'items' => [['id' => 'd_1', 'status' => 'ACTIVE'], ['id' => 'd_2', 'status' => 'DRAFT']],
            'total' => 2,
            'pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => 2],
        ]]);

        $dealers = $mp->listDealers();

        $this->assertCount(2, $dealers);
        $this->assertSame('d_1', $dealers[0]->id());
        $this->assertTrue($dealers[0]->isActive());
    }

    public function testSellerRequiresPositiveAmount(): void
    {
        $this->expectException(ConfigurationException::class);
        new MarketplaceSeller('d_1', 0);
    }

    public function testCreateCheckoutIntentThrowsWhenNoToken(): void
    {
        [$mp, $http] = $this->make();
        // Gateway rejects the split (e.g. seller ineligible) — success=false, no token.
        $http->pushJson(200, ['success' => false, 'code' => 'marketplace.payment.seller_not_eligible', 'message' => 'seller not eligible']);

        $this->expectException(\Lunixi\Sdk\Exception\ApiException::class);
        $mp->createCheckoutIntent(new CreateIntentRequest(1000, 'TRY', 'o1'), [new MarketplaceSeller('d_1', 1000)]);
    }

    public function testActivateDealerRoute(): void
    {
        [$mp, $http] = $this->make();
        $http->pushJson(200, ['success' => true]);

        $mp->activateDealer('d_1');
        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/marketplace/dealers/d_1/activate', $call['url']);
    }

    public function testCreatePayoutAndResolveCommission(): void
    {
        [$mp, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'payoutId' => 'po_1']);
        $mp->createPayout(['subDealerId' => 'd_1', 'amountMinor' => 5000, 'currency' => 'TRY']);
        $this->assertStringEndsWith('/api/v1/marketplace/payouts', $http->lastCall()['url']);

        $http->pushJson(200, ['success' => true, 'rateBps' => 250]);
        $mp->resolveCommission('d_1', 'ELECTRONICS', 100000);
        $url = $http->lastCall()['url'];
        $this->assertStringContainsString('/api/v1/marketplace/commission/resolve', $url);
        $this->assertStringContainsString('subDealerId=d_1', $url);
        $this->assertStringContainsString('categoryCode=ELECTRONICS', $url);
    }
}
