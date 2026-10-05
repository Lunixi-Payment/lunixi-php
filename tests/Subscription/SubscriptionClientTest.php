<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Subscription;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Subscription\AddCardRequest;
use Lunixi\Sdk\Subscription\ChangePlanRequest;
use Lunixi\Sdk\Subscription\CollectionMethod;
use Lunixi\Sdk\Subscription\CreateSubscriptionRequest;
use Lunixi\Sdk\Subscription\PortalClient;
use Lunixi\Sdk\Subscription\SubscriptionClient;
use Lunixi\Sdk\Subscription\SubscriptionStatus;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class SubscriptionClientTest extends TestCase
{
    /** @return array{0:SubscriptionClient,1:FakeHttpClient} */
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

        return [new SubscriptionClient(new ApiClient($config, $signer, $tokens, $http)), $http];
    }

    public function testCreateSubscriptionIsBearerOnlyWithIdempotencyKey(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'code' => 'SUBSCRIPTION_ACTIVE', 'data' => [
            'id' => 'sub_1', 'status' => 'ACTIVE', 'customerId' => 'cus_1', 'planId' => 'plan_1',
            'priceSnapshotAmount' => 9900, 'priceSnapshotCurrency' => 'TRY', 'nextBillingAt' => '2026-07-16T10:00:00Z',
        ]]);

        $req = (new CreateSubscriptionRequest('cus_1', 'plan_1', 'idem-sub-1'))
            ->withPaymentCardId('card_1')
            ->withCollectionMethod(CollectionMethod::AUTO_CHARGE);
        $sub = $subs->create($req);

        $this->assertSame('sub_1', $sub->id());
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status());
        $this->assertTrue($sub->isActive());
        $this->assertSame(9900, $sub->priceAmount()); // minor units
        $this->assertSame('2026-07-16T10:00:00Z', $sub->nextBillingAt());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/subscriptions/subscriptions', $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'subscriptions are bearer-only, not step-up');
        $this->assertSame('idem-sub-1', $call['headers']['Idempotency-Key']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('cus_1', $body['customerId']);
        $this->assertSame('plan_1', $body['planId']);
        $this->assertSame('card_1', $body['paymentCardId']);
        $this->assertSame('AUTO_CHARGE', $body['collectionMethod']);
        $this->assertSame('idem-sub-1', $body['idempotencyKey']);
    }

    public function testCancelAtPeriodEndSendsFlag(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'sub_1', 'status' => 'ACTIVE', 'cancelAtPeriodEnd' => true]]);

        $sub = $subs->cancel('sub_1', true, 'too expensive');

        $this->assertTrue($sub->cancelAtPeriodEnd());
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/subscriptions/subscriptions/sub_1/cancel', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertTrue($body['cancelAtPeriodEnd']);
        $this->assertSame('too expensive', $body['reason']);
    }

    public function testPauseAndResume(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'sub_1', 'status' => 'PAUSED']]);
        $paused = $subs->pause('sub_1', '2026-09-01T00:00:00Z');
        $this->assertTrue($paused->isPaused());
        $this->assertStringEndsWith('/pause', $http->lastCall()['url']);
        $this->assertSame('2026-09-01T00:00:00Z', json_decode((string) $http->lastCall()['body'], true)['resumeAt']);

        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'sub_1', 'status' => 'ACTIVE']]);
        $resumed = $subs->resume('sub_1');
        $this->assertTrue($resumed->isActive());
        $this->assertStringEndsWith('/resume', $http->lastCall()['url']);
        $this->assertNull($http->lastCall()['body']);
    }

    public function testChangePlanSendsBehaviorAndIdempotencyKey(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'sub_1', 'status' => 'ACTIVE', 'planId' => 'plan_2']]);

        $req = (new ChangePlanRequest('plan_2'))
            ->withBehavior(ChangePlanRequest::IMMEDIATE)
            ->withIdempotencyKey('idem-chg-1');
        $sub = $subs->changePlan('sub_1', $req);

        $this->assertSame('plan_2', $sub->planId());
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/subscriptions/subscriptions/sub_1/change-plan', $call['url']);
        $this->assertSame('idem-chg-1', $call['headers']['Idempotency-Key']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('plan_2', $body['newPlanId']);
        $this->assertSame('IMMEDIATE', $body['behavior']);
    }

    public function testPlansListParsesCursorPagination(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(200, [
            'success' => true,
            'data' => [['id' => 'plan_1', 'name' => 'Pro', 'price' => 9900, 'currency' => 'TRY']],
            'nextPageToken' => 'tok_next', 'pageSize' => 20,
        ]);

        $list = $subs->plans()->list(['includeArchived' => false, 'productId' => 'prod_1']);

        $this->assertCount(1, $list->items());
        $this->assertSame('Pro', $list->items()[0]->name());
        $this->assertSame(9900, $list->items()[0]->price());
        $this->assertTrue($list->hasMore());
        $this->assertSame('tok_next', $list->nextPageToken());

        $url = $http->lastCall()['url'];
        $this->assertStringContainsString('includeArchived=false', $url); // bool stringified, not 1/0
        $this->assertStringContainsString('productId=prod_1', $url);
    }

    public function testAddCardSendsTokenNeverPan(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'data' => ['id' => 'card_1', 'brand' => 'VISA', 'last4' => '4242', 'isDefault' => true]]);

        $req = (new AddCardRequest('cus_1', 'tok_abc'))
            ->withBrand('VISA')->withLast4('4242')->withExpiry(12, 2030)->withIsDefault(true);
        $card = $subs->cards()->add($req);

        $this->assertSame('4242', $card->last4());
        $this->assertTrue($card->isDefault());
        $call = $http->lastCall();
        $this->assertStringEndsWith('/api/v1/subscriptions/cards', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('tok_abc', $body['paymentMethodToken']);
        $this->assertArrayNotHasKey('pan', $body);
        $this->assertArrayNotHasKey('cardNumber', $body);
    }

    public function testPortalSdkSessionReadsRootLevelProof(): void
    {
        [$subs, $http] = $this->make();
        // The gateway returns proof fields at the response ROOT (spread w/ OK), not under data.
        $http->pushJson(200, [
            'success' => true, 'code' => 'OK', 'message' => '',
            'portalToken' => 'cp_abc', 'sessionProof' => 'hmac_xyz',
            'proofNonce' => 'nonce_1', 'proofExpiresAt' => '2026-06-16T03:00:00Z',
        ]);

        $session = $subs->portal()->createSdkSession('cus_1', 'https://shop.example', PortalClient::MODE_HYBRID);

        $this->assertSame('cp_abc', $session->portalToken());
        $this->assertSame('hmac_xyz', $session->sessionProof());
        $this->assertSame('nonce_1', $session->proofNonce());
        $boot = $session->toBootstrap();
        $this->assertSame('cp_abc', $boot['portalToken']);

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/subscriptions/portal/sdk-session', $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers']); // bearer-only
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('cus_1', $body['customerId']);
        $this->assertSame('https://shop.example', $body['origin']);
        $this->assertSame('HYBRID', $body['sdkMode']);
    }

    public function testPortalHostedLinkBuildsUrl(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'data' => [
            'id' => 'lnk_1', 'customerId' => 'cus_1', 'token' => 'cp_xyz', 'status' => 'ACTIVE',
        ]]);

        $link = $subs->portal()->createPortalLink('cus_1', ['allowedPlanIds' => ['plan_1']]);

        $this->assertSame('cp_xyz', $link->token());
        $this->assertSame('https://portal.lunixi.com/?token=cp_xyz', $link->hostedUrl());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/panel/v1/subscriptions/portal/links', $call['url']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('cus_1', $body['customerId']);
        $this->assertSame(['plan_1'], $body['allowedPlanIds']);
    }

    public function testCustomerByExternalIdPathAndInvoicesFilter(): void
    {
        [$subs, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'cus_1', 'externalId' => 'wp-42']]);
        $cus = $subs->customers()->getByExternalId('wp-42');
        $this->assertSame('cus_1', $cus->id());
        $this->assertStringEndsWith('/api/v1/subscriptions/customers/by-external/wp-42', $http->lastCall()['url']);

        $http->pushJson(200, ['success' => true, 'data' => [['id' => 'inv_1', 'status' => 'PAID', 'totalAmount' => 9900, 'currency' => 'TRY']]]);
        $list = $subs->invoices()->list(['subscriptionId' => 'sub_1', 'status' => 'PAID']);
        $this->assertTrue($list->items()[0]->isPaid());
        $this->assertSame(9900, $list->items()[0]->totalAmount());
        $this->assertStringContainsString('subscriptionId=sub_1', $http->lastCall()['url']);
    }

    public function testCatalogProductsAndUsageRoutes(): void
    {
        [$subs, $http] = $this->make();

        $http->pushJson(200, ['id' => 'prod_1']);
        $subs->products()->create(['name' => 'Pro']);
        $this->assertSame('POST', $http->lastCall()['method']);
        $this->assertStringEndsWith('/api/v1/subscriptions/products', $http->lastCall()['url']);

        $http->pushJson(200, ['entitled' => true, 'remaining' => 42]);
        $subs->usage()->checkEntitlement(['customerId' => 'cus_1', 'featureKey' => 'api_calls']);
        $this->assertStringEndsWith('/api/v1/subscriptions/usage/check-entitlement', $http->lastCall()['url']);
    }

    public function testPlanUpdateAndPortalLinkManagement(): void
    {
        [$subs, $http] = $this->make();

        $http->pushJson(200, ['success' => true, 'data' => ['id' => 'plan_1', 'amount' => 1500]]);
        $plan = $subs->plans()->update('plan_1', ['amount' => 1500]);
        $this->assertSame('plan_1', $plan->id());
        $this->assertSame('PATCH', $http->lastCall()['method']);
        $this->assertStringEndsWith('/api/v1/subscriptions/plans/plan_1', $http->lastCall()['url']);

        $http->pushJson(200, ['items' => [['id' => 'lnk_1']]]);
        $subs->portal()->listLinks(['status' => 'active']);
        $this->assertStringContainsString('/api/panel/v1/subscriptions/portal/links', $http->lastCall()['url']);
    }
}
