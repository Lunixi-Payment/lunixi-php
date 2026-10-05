<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Kyc;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Kyc\AchievedAssurance;
use Lunixi\Sdk\Kyc\AssuranceLevel;
use Lunixi\Sdk\Kyc\CreateSessionRequest;
use Lunixi\Sdk\Kyc\KycClient;
use Lunixi\Sdk\Kyc\SessionStatus;
use Lunixi\Sdk\Kyc\SessionType;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class KycClientTest extends TestCase
{
    /** @return array{0:KycClient,1:FakeHttpClient} */
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

        return [new KycClient(new ApiClient($config, $signer, $tokens, $http)), $http];
    }

    public function testCreateSessionIsBearerOnlyAndSendsExternalCustomerId(): void
    {
        [$kyc, $http] = $this->make();
        $http->pushJson(201, ['success' => true, 'data' => [
            'session' => ['sessionId' => 'sess_1', 'status' => 'CREATED', 'sessionType' => 'KYC', 'externalCustomerId' => '42'],
        ]]);

        $req = (new CreateSessionRequest('42', SessionType::KYC))
            ->withExternalCustomerId('42')
            ->withJurisdiction('TR');
        $session = $kyc->createSession($req);

        $this->assertSame('sess_1', $session->sessionId());
        $this->assertSame(SessionStatus::CREATED, $session->status());
        $this->assertSame('42', $session->externalCustomerId());

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/kyc/sessions', $call['url']);
        $this->assertArrayNotHasKey('X-Signature', $call['headers'], 'KYC is bearer-only, not step-up');
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('42', $body['subjectId']);
        $this->assertSame('KYC', $body['sessionType']);
        $this->assertSame('42', $body['externalCustomerId']);
        $this->assertSame('TR', $body['jurisdiction']);
    }

    public function testGetSessionParsesDecisionAndPerDomainAssurance(): void
    {
        [$kyc, $http] = $this->make();
        $http->pushJson(200, ['success' => true, 'data' => [
            'session' => [
                'sessionId' => 'sess_9', 'status' => 'APPROVED', 'sessionType' => 'KYC',
                'expiresAt' => '2027-06-16T00:00:00Z',
                'decision' => [
                    'finalDisposition' => 'APPROVED', 'riskLevel' => 'LOW',
                    'explanationSummary' => 'All checks passed.',
                    'assuranceSnapshot' => ['achieved' => [
                        'identity_evidence_strength' => 'HIGH',
                        'address_assurance' => 'LOW',
                        'aml_freshness' => 'SUBSTANTIAL',
                        'meetsProfile' => true,
                    ]],
                ],
            ],
        ]]);

        $session = $kyc->getSession('sess_9');

        $this->assertTrue($session->isApproved());
        $this->assertTrue($session->isTerminal());
        $this->assertSame('APPROVED', $session->decision());
        $this->assertSame('LOW', $session->riskLevel());
        $this->assertSame('2027-06-16T00:00:00Z', $session->expiresAt());

        $assurance = $session->assurance();
        $this->assertNotNull($assurance);
        $this->assertSame('HIGH', $assurance->identity());
        $this->assertSame('LOW', $assurance->address());
        $this->assertTrue($assurance->meetsProfile());
        // per-domain gate check
        $this->assertTrue(AssuranceLevel::meets($assurance->identity(), AssuranceLevel::SUBSTANTIAL));
        $this->assertFalse(AssuranceLevel::meets($assurance->address(), AssuranceLevel::SUBSTANTIAL));
        $this->assertArrayHasKey(AchievedAssurance::IDENTITY, $assurance->toMap());

        $this->assertSame('GET', $http->lastCall()['method']);
        $this->assertArrayNotHasKey('X-Signature', $http->lastCall()['headers']);
    }


    /**
     * 🔴 BU TEST ESKİDEN HATAYI KİLİTLİYORDU.
     *
     * Önceki hâli `externalCustomerId`/`status` gönderildiğini doğruluyordu —
     * oysa `GET /api/v1/kyc/sessions` bu alanları KABUL ETMİYOR ve gateway
     * `forbidNonWhitelisted` ile koştuğu için gerçek çağrı **400** dönüyordu.
     * Test sahte HTTP kullandığı için bunu göremiyordu: yeşil test, kırık ürün.
     *
     * Artık desteklenmeyen filtre AÇIKÇA reddediliyor (sessizce düşürmek,
     * çağırana filtrelenmemiş listeyi "filtrelenmiş" diye vermek olurdu).
     */
    public function testListSessionsRejectsUnsupportedFilters(): void
    {
        [$kyc] = $this->make();

        $this->expectException(\InvalidArgumentException::class);
        $kyc->listSessions(['externalCustomerId' => '42', 'status' => 'APPROVED']);
    }

    /**
     * Gerçek gateway zarfı: `{status,code,data:{items,pageInfo}}`.
     * Eskiden `data` bir oturum DİZİSİ sanılıyordu → items çöp, hasMore daima false.
     */
    public function testListSessionsParsesCursorEnvelope(): void
    {
        [$kyc, $http] = $this->make();
        $http->pushJson(200, [
            'status' => 'success',
            'code' => 'SUCCESS',
            'data' => [
                'items' => [
                    ['session' => ['sessionId' => 's1', 'status' => 'APPROVED']],
                    ['session' => ['sessionId' => 's2', 'status' => 'PROCESSING']],
                ],
                'nextCursor' => 'tok2',
                'pageInfo' => ['hasMore' => true, 'nextCursor' => 'tok2', 'totalCount' => null],
            ],
        ]);

        $list = $kyc->listSessions(['limit' => 2]);

        $this->assertCount(2, $list->items());
        $this->assertSame('s1', $list->items()[0]->sessionId());
        $this->assertTrue($list->hasMore());
        $this->assertSame('tok2', $list->nextPageToken());
        $this->assertStringContainsString('limit=2', $http->lastCall()['url']);
    }

    /** Son sayfada imleç yok → hasMore false. */
    public function testListSessionsLastPageHasNoCursor(): void
    {
        [$kyc, $http] = $this->make();
        $http->pushJson(200, ['status' => 'success', 'data' => [
            'items' => [['session' => ['sessionId' => 's9']]],
            'pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => null],
        ]]);

        $list = $kyc->listSessions();

        $this->assertCount(1, $list->items());
        $this->assertFalse($list->hasMore());
        $this->assertNull($list->nextPageToken());
    }

    /** `pageSize`/`pageToken` GERİYE UYUMLU: tel üzerinde `limit`/`cursor` olur. */
    public function testListSessionsMapsLegacyPageParams(): void
    {
        [$kyc, $http] = $this->make();
        $http->pushJson(200, ['status' => 'success', 'data' => ['items' => [], 'pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => null]]]);

        $kyc->listSessions(['pageSize' => 50, 'pageToken' => 'abc']);

        $url = $http->lastCall()['url'];
        $this->assertStringContainsString('limit=50', $url);
        $this->assertStringContainsString('cursor=abc', $url);
        $this->assertStringNotContainsString('pageSize', $url);
        $this->assertStringNotContainsString('pageToken', $url);
    }

    public function testCreateSessionValidatesType(): void
    {
        $this->expectException(ConfigurationException::class);
        new CreateSessionRequest('42', 'NOPE');
    }

    public function testSetPrimaryDocumentAndMerchantPolicyRoutes(): void
    {
        [$kyc, $http] = $this->make();

        $http->pushJson(200, ['success' => true, 'data' => ['sessionId' => 's1']]);
        $kyc->setPrimaryDocument('s1', 'PASSPORT');
        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/api/v1/kyc/sessions/s1/primary-document', $call['url']);
        $this->assertSame('PASSPORT', json_decode((string) $call['body'], true)['documentType']);

        $http->pushJson(200, ['requiredAssurance' => 'LOW']);
        $kyc->merchantPolicy();
        $this->assertStringEndsWith('/api/v1/kyc/admin/merchant-policy', $http->lastCall()['url']);
    }
}
