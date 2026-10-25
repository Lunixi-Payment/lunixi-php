<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Insurance;

use DateTimeImmutable;
use DateTimeZone;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\HttpResponse;
use Lunixi\Sdk\Insurance\InsuranceClient;
use Lunixi\Sdk\Insurance\InsuranceCollectionStatus;
use Lunixi\Sdk\Insurance\InsuranceDocumentStatus;
use Lunixi\Sdk\Insurance\InsuranceEvents;
use Lunixi\Sdk\Insurance\InsuranceExcludedQuoteOption;
use Lunixi\Sdk\Insurance\InsuranceLead;
use Lunixi\Sdk\Insurance\InsuranceLeadStatus;
use Lunixi\Sdk\Insurance\InsuranceLeadWebhookData;
use Lunixi\Sdk\Insurance\InsuranceOrder;
use Lunixi\Sdk\Insurance\InsuranceOrderPolicy;
use Lunixi\Sdk\Insurance\InsuranceOrderStatus;
use Lunixi\Sdk\Insurance\InsuranceOrderSurface;
use Lunixi\Sdk\Insurance\InsurancePaymentMode;
use Lunixi\Sdk\Insurance\InsurancePolicyStatus;
use Lunixi\Sdk\Insurance\InsurancePremiumCollectedBy;
use Lunixi\Sdk\Insurance\InsuranceProduct;
use Lunixi\Sdk\Insurance\InsuranceProvider;
use Lunixi\Sdk\Insurance\InsuranceQuoteOption;
use Lunixi\Sdk\Insurance\InsuranceQuoteSet;
use Lunixi\Sdk\Insurance\InsuranceQuoteStatus;
use Lunixi\Sdk\Insurance\InsuranceReconciliationGroup;
use Lunixi\Sdk\Insurance\InsuranceReconciliationOrder;
use Lunixi\Sdk\Insurance\InsuranceReconciliationPolicy;
use Lunixi\Sdk\Insurance\InsuranceReconciliationSummary;
use Lunixi\Sdk\Insurance\InsuranceReconciliationTotals;
use Lunixi\Sdk\Insurance\InsuranceReferenceKind;
use Lunixi\Sdk\Insurance\InsuranceReferenceValue;
use Lunixi\Sdk\Insurance\InsuranceRefundStatus;
use Lunixi\Sdk\Insurance\InsuranceUnderwriter;
use Lunixi\Sdk\Insurance\InsuranceResource;
use Lunixi\Sdk\Insurance\InsuranceTerms;
use Lunixi\Sdk\Insurance\InsuranceWebhookData;
use Lunixi\Sdk\Insurance\InsuranceWebhookLead;
use Lunixi\Sdk\Insurance\InsuranceWebhookPolicy;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\InsuranceItemCategory;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\SignatureGuardStandIn;
use Lunixi\Sdk\Webhook\WebhookEvent;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract source: nest-payment-gateway apps/api-gateway/src/insurance/
 * (api/insurance-api.controller.ts, api/insurance-api.service.ts, dtos/*.ts,
 * mappers/insurance-json.mapper.ts, mappers/insurance-reconciliation.mapper.ts,
 * insurance-reconciliation.export.ts, insurance-policy-document.response.ts);
 * apps/insurance-service/src/standards/insurance-order-status.ts,
 * insurance-lead-standards.ts; webhook libs/proto/src/insurance-webhook.interface.ts
 * and apps/webhook-service/src/deliveries/policies/insurance-webhook.policy.ts.
 * Ids and values below are synthetic.
 */
final class InsuranceClientTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';
    private const PAYMENT_ID = '7c1e2d3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
    private const CONNECTION_ID = '8d2f3e4a-5b6c-4d7e-9f8a-0b1c2d3e4f5a';
    private const QUOTE_ID = 'insq_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ORDER_ID = 'inso_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const POLICY_ID = 'insp_cccccccccccccccccccccccccccccccc';
    private const LEAD_ID = 'insl_ffffffffffffffffffffffffffffffff';
    private const KEY = 'insurance-test-key-0001';
    private const RECONCILIATION = ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-02-01T00:00:00Z'];

    /** Raw 32-byte Ed25519 public key of the client under test. */
    private string $publicKey = '';

    /** @return array{0:InsuranceClient,1:FakeHttpClient} */
    private function make(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $this->publicKey = SignatureGuardStandIn::rawPublicKey($keys['publicKey']);

        $config = new Configuration([
            'baseUrl' => self::BASE_URL,
            'keyId' => 'mk_test_sk_kid_1',
            'privateKey' => $keys['privateKey'],
            'maxRetries' => 0,
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $store->set($config->tokenCacheKey(), 'at_test_seeded', 3600);

        return [new InsuranceClient(new ApiClient($config, $signer, new TokenManager($config, $signer, $http, $store), $http)), $http];
    }

    /** @param array{method:string,url:string,headers:array<string,string>,body:?string} $call */
    private function verdict(array $call): string
    {
        return SignatureGuardStandIn::verdict($call, $this->publicKey, self::BASE_URL);
    }

    /** @return array<string,mixed> */
    private static function envelope(array $data, array $extra = []): array
    {
        return array_merge(['status' => 'success', 'code' => 'SUCCESS', 'data' => $data, 'requestId' => 'req_1'], $extra);
    }

    /** List envelope: rows in `data.items`, paging in the top-level `pageInfo`. */
    private static function page(array $items, bool $hasMore = false, ?string $cursor = null): array
    {
        return self::envelope(['items' => $items], ['pageInfo' => ['hasMore' => $hasMore, 'nextCursor' => $cursor]]);
    }

    private static function binary(string $bytes, string $contentType): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => $contentType], $bytes);
    }

    /** @param array{url:string} $call */
    private static function target(array $call): string
    {
        return substr($call['url'], strlen(self::BASE_URL));
    }

    /** @param array{url:string} $call @return array<string,string> */
    private static function queryOf(array $call): array
    {
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    /** @param array{body:?string} $call @return mixed */
    private static function bodyOf(array $call)
    {
        return $call['body'] === null ? null : json_decode($call['body'], true);
    }

    private static function ticketItem(): BasketItem
    {
        return new BasketItem([
            'id' => 'ticket-1',
            'price' => 100000,
            'name' => 'Sample ticket',
            'category1' => 'ticket',
            'itemType' => BasketItem::TYPE_VIRTUAL,
            'insuranceCategory' => InsuranceItemCategory::TICKET,
            'insuranceTypes' => ['TICKET_CANCELLATION'],
        ]);
    }

    // ── Fixtures: exactly the fields the gateway mappers emit ─────────────────

    private static function providerJson(): array
    {
        return ['code' => 'SAMPLE', 'name' => 'Sample', 'logoUrl' => null, 'connectionId' => self::CONNECTION_ID];
    }

    private static function productJson(): array
    {
        return [
            'insuranceType' => 'TICKET_CANCELLATION', 'typeObject' => 'tickets', 'schemaVersion' => 1, 'basketItemIds' => ['ticket-1'],
            'title' => ['tr' => 'Bilet iptal', 'en' => 'Ticket cancellation'], 'description' => [], 'fields' => [['name' => 'tickets']],
        ];
    }

    private static function quoteOptionJson(array $overrides = []): array
    {
        return array_merge([
            'quoteId' => self::QUOTE_ID, 'insuranceType' => 'TICKET_CANCELLATION', 'schemaVersion' => 1, 'title' => 'Ticket cancellation',
            'premiumAmount' => 4900, 'currency' => 'TRY', 'premiumCollectedBy' => 'MERCHANT', 'provider' => self::providerJson(),
            'basketItemIds' => ['ticket-1'], 'coverageSummary' => [], 'terms' => ['version' => 'v1', 'sha256' => str_repeat('d', 64)],
            'underwriterName' => null, 'expiresAt' => '2026-01-01T07:15:00.000Z', 'status' => 'QUOTED', 'paymentIntentId' => null,
            'createdAt' => '2026-01-01T07:00:00.000Z',
        ], $overrides);
    }

    private static function orderJson(array $overrides = []): array
    {
        return array_merge([
            'orderId' => self::ORDER_ID, 'quoteId' => self::QUOTE_ID, 'insuranceType' => 'TICKET_CANCELLATION', 'surface' => 'MERCHANT_API',
            'status' => 'CREATED', 'collectionStatus' => 'NOT_STARTED', 'policyStatus' => 'NOT_REQUESTED', 'refundStatus' => 'NONE',
            'documentStatus' => 'NOT_AVAILABLE', 'paymentMode' => 'NO_PAYMENT', 'premiumCollectedBy' => 'MERCHANT', 'premiumAmount' => 4900,
            'currency' => 'TRY', 'paymentIntentId' => null, 'merchantOrderId' => 'ORD-1', 'provider' => self::providerJson(),
            'basketItemIds' => ['ticket-1'], 'policies' => [], 'errorCode' => null, 'refundErrorCode' => null, 'refundedAt' => null,
            'createdAt' => '2026-01-01T07:00:00.000Z', 'updatedAt' => '2026-01-01T07:00:00.000Z',
        ], $overrides);
    }

    private static function totalsJson(): array
    {
        return [
            'orderCount' => 2, 'premiumAmount' => 9800, 'collectedOrderCount' => 2, 'collectedPremiumAmount' => 9800,
            'policyCount' => 2, 'inForcePolicyCount' => 1, 'inForcePremiumAmount' => 4900, 'cancelledPolicyCount' => 1,
            'cancelledPremiumAmount' => 4900, 'refundedOrderCount' => 1, 'refundedPremiumAmount' => 4900,
            'refundOpenOrderCount' => 0, 'refundOpenPremiumAmount' => 0,
        ];
    }

    private static function reconciliationOrderJson(): array
    {
        return [
            'orderId' => self::ORDER_ID, 'merchantOrderId' => 'ORD-1', 'paymentIntentId' => self::PAYMENT_ID, 'externalPaymentId' => null,
            'insuranceType' => 'TICKET_CANCELLATION', 'connectionId' => self::CONNECTION_ID, 'providerCode' => 'SAMPLE',
            'premiumCollectedBy' => 'MERCHANT', 'paymentMode' => 'LUNIXI_PAYMENT', 'premiumAmount' => 4900, 'currency' => 'TRY',
            'collectionStatus' => 'COLLECTED', 'policyStatus' => 'ISSUED', 'refundStatus' => 'NONE', 'createdAt' => '2026-01-01T07:00:00.000Z',
            'collectedAt' => '2026-01-01T07:05:00.000Z', 'refundedAt' => null,
            'policies' => [[
                'policyId' => self::POLICY_ID, 'basketItemId' => 'ticket-1', 'policyNumber' => 'P-1', 'policyStatus' => 'ISSUED',
                'premiumAmount' => 4900, 'cancelledAt' => null,
            ]],
        ];
    }

    private static function leadJson(array $overrides = []): array
    {
        return array_merge([
            'leadId' => self::LEAD_ID, 'insuranceType' => 'CARGO_TRANSIT', 'schemaVersion' => 1, 'status' => 'SUBMITTED', 'errorCode' => null,
            'requestMasked' => ['policyholder' => ['identityNumber' => '***'], 'shipment' => ['originCityCode' => '35'], 'note' => '***'],
            'submittedAt' => '2026-01-01T07:00:01.000Z', 'createdAt' => '2026-01-01T07:00:00.000Z', 'updatedAt' => '2026-01-01T07:00:01.000Z',
        ], $overrides);
    }

    /**
     * A lead body: synthetic values, the field names of the CARGO_TRANSIT lead schema v1.
     *
     * @return array<string,mixed>
     */
    private static function leadRequest(): array
    {
        return [
            'insuranceType' => 'CARGO_TRANSIT',
            'policyholder' => ['identityType' => 'TCKN', 'identityNumber' => 'SAMPLE', 'birthDate' => '1990-01-01', 'email' => 'lead@example.com', 'phone' => '+905000000000'],
            'company' => ['identityType' => 'VKN', 'identityNumber' => 'SAMPLE'],
            'shipment' => [
                'cargoDescription' => 'Sample goods', 'carrierVehicle' => 'TRUCK', 'invoiceAmount' => 1000000, 'consigneeName' => 'Sample Consignee',
                'originCityCode' => '35', 'destinationCityCode' => '06', 'departureDate' => '2026-01-02',
            ],
            'vehicle' => ['plate' => 'SAMPLE', 'registrationDocument' => ['serial' => 'AA', 'number' => '000000']],
            'note' => '  Kept as given  ',
        ];
    }

    /** @return array<string,mixed> */
    private static function quoteRequest(): array
    {
        return ['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => [self::ticketItem()]], 'tickets' => [['basketItemId' => 'ticket-1']]];
    }

    /** @return array<string,string> */
    private static function consent(): array
    {
        return ['termsVersion' => 'v1', 'termsSha256' => str_repeat('d', 64), 'acceptedAt' => '2026-01-01T07:01:00Z'];
    }

    /** @return array<string,mixed> */
    private static function externalPayment(): array
    {
        return ['externalPaymentId' => 'pay-1', 'paidAmount' => 4900, 'paidCurrency' => 'TRY', 'paidAt' => '2026-01-01T07:02:00Z'];
    }

    // ── Signing and routes ────────────────────────────────────────────────────

    public function testEveryCallIsSignedHitsItsRouteAndSendsTheIdempotencyKeyItNeeds(): void
    {
        [$client, $http] = $this->make();
        $invocations = [
            'listProducts' => ['GET /api/v1/insurance/products', null, static fn () => $client->listProducts(['currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID])],
            'searchProducts' => ['POST /api/v1/insurance/products', null, static fn () => $client->searchProducts(['currency' => 'TRY', 'context' => ['items' => [self::ticketItem()]]])],
            'listReferenceValues' => ['GET /api/v1/insurance/reference/CITY', null, static fn () => $client->listReferenceValues('CITY')],
            'iterateReferenceValues' => ['GET /api/v1/insurance/reference/DISTRICT', null, static fn () => iterator_to_array($client->iterateReferenceValues('DISTRICT', ['parentCode' => '34']))],
            'createQuote' => ['POST /api/v1/insurance/quotes', 'optional', static fn () => $client->createQuote(self::quoteRequest(), self::KEY)],
            'getQuote' => ['GET /api/v1/insurance/quotes/' . self::QUOTE_ID, null, static fn () => $client->getQuote(self::QUOTE_ID)],
            'createOrder' => ['POST /api/v1/insurance/orders', 'required', static fn () => $client->createOrder(['quoteId' => self::QUOTE_ID, 'consent' => self::consent()], self::KEY)],
            'listOrders' => ['GET /api/v1/insurance/orders', null, static fn () => $client->listOrders()],
            'iterateOrders' => ['GET /api/v1/insurance/orders', null, static fn () => iterator_to_array($client->iterateOrders())],
            'getOrder' => ['GET /api/v1/insurance/orders/' . self::ORDER_ID, null, static fn () => $client->getOrder(self::ORDER_ID)],
            'bindPayment' => ['POST /api/v1/insurance/orders/' . self::ORDER_ID . '/bind-payment', 'optional', static fn () => $client->bindPayment(self::ORDER_ID, self::PAYMENT_ID, self::KEY)],
            'confirmPayment' => ['POST /api/v1/insurance/orders/' . self::ORDER_ID . '/confirm-payment', 'required', static fn () => $client->confirmPayment(self::ORDER_ID, self::externalPayment(), self::KEY)],
            'issuePolicy' => ['POST /api/v1/insurance/orders/' . self::ORDER_ID . '/issue-policy', 'required', static fn () => $client->issuePolicy(self::ORDER_ID, self::KEY)],
            'cancelOrder' => ['POST /api/v1/insurance/orders/' . self::ORDER_ID . '/cancel', 'required', static fn () => $client->cancelOrder(self::ORDER_ID, self::KEY)],
            'refundPremium' => ['POST /api/v1/insurance/orders/' . self::ORDER_ID . '/refund-premium', 'required', static fn () => $client->refundPremium(self::ORDER_ID, [], self::KEY)],
            'createLead' => ['POST /api/v1/insurance/leads', 'required', static fn () => $client->createLead(self::leadRequest(), self::KEY)],
            'listLeads' => ['GET /api/v1/insurance/leads', null, static fn () => $client->listLeads()],
            'iterateLeads' => ['GET /api/v1/insurance/leads', null, static fn () => iterator_to_array($client->iterateLeads())],
            'getLead' => ['GET /api/v1/insurance/leads/' . self::LEAD_ID, null, static fn () => $client->getLead(self::LEAD_ID)],
            'getPolicyDocument' => ['GET /api/v1/insurance/policies/' . self::POLICY_ID . '/document', null, static fn () => $client->getPolicyDocument(self::POLICY_ID)],
            'getPolicyReceipt' => ['GET /api/v1/insurance/policies/' . self::POLICY_ID . '/receipt', null, static fn () => $client->getPolicyReceipt(self::POLICY_ID)],
            'getPolicyCertificate' => ['GET /api/v1/insurance/policies/' . self::POLICY_ID . '/certificate', null, static fn () => $client->getPolicyCertificate(self::POLICY_ID)],
            'getReconciliationSummary' => ['GET /api/v1/insurance/reconciliation/summary', null, static fn () => $client->getReconciliationSummary(self::RECONCILIATION)],
            'listReconciliationOrders' => ['GET /api/v1/insurance/reconciliation/orders', null, static fn () => $client->listReconciliationOrders(self::RECONCILIATION)],
            'iterateReconciliationOrders' => ['GET /api/v1/insurance/reconciliation/orders', null, static fn () => iterator_to_array($client->iterateReconciliationOrders(self::RECONCILIATION))],
            'exportReconciliation' => ['GET /api/v1/insurance/reconciliation/export', null, static fn () => $client->exportReconciliation(self::RECONCILIATION)],
        ];

        // The lock covers the whole public surface: a method added later without an
        // entry above fails here instead of shipping unsigned.
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new ReflectionClass(InsuranceClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m): bool => !$m->isConstructor() && !$m->isStatic()
            )
        );
        sort($methods);
        $expected = array_keys($invocations);
        sort($expected);
        $this->assertSame($expected, $methods);

        foreach ($invocations as $name => [$route, $key, $invoke]) {
            if (str_starts_with($name, 'getPolicy')) {
                $http->push(self::binary('%PDF-1.7', 'application/pdf'));
            } elseif ($name === 'exportReconciliation') {
                $http->push(self::binary("\u{FEFF}orderId\r\n", 'text/csv; charset=utf-8'));
            } elseif (str_contains($name, 'list') && $name !== 'listProducts' || str_contains($name, 'iterate')) {
                $http->pushJson(200, self::page([]));
            } elseif (str_contains($name, 'Lead')) {
                $http->pushJson(200, self::envelope(self::leadJson()));
            } else {
                $http->pushJson(200, self::envelope(self::orderJson()));
            }
            $before = $http->callCount();
            $invoke();
            $this->assertSame($before + 1, $http->callCount(), "{$name} sends one request");
            $call = $http->lastCall();
            $this->assertSame($route, $call['method'] . ' ' . strtok(self::target($call), '?'), $name);
            $this->assertSame('OK', $this->verdict($call), "{$name} must carry a valid API-key signature");
            $this->assertSame($key === null ? null : self::KEY, $call['headers']['Idempotency-Key'] ?? null, "{$name} Idempotency-Key");
            if ($call['method'] === 'GET') {
                $this->assertNull($call['body'], "{$name} sends no body");
            }
        }
    }

    public function testFacadeExposesTheClient(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $lunixi = LunixiClient::create(['baseUrl' => self::BASE_URL, 'keyId' => 'kid', 'privateKey' => $keys['privateKey']], new FakeHttpClient());
        $this->assertInstanceOf(InsuranceClient::class, $lunixi->insurance());
    }

    // ── Idempotency-Key ───────────────────────────────────────────────────────

    public function testTheFiveOrderWritesAndTheLeadRefuseAMissingOrMalformedKeyBeforeSending(): void
    {
        [$client, $http] = $this->make();
        $writes = [
            static fn (string $key) => $client->createOrder(['quoteId' => self::QUOTE_ID, 'consent' => self::consent()], $key),
            static fn (string $key) => $client->confirmPayment(self::ORDER_ID, self::externalPayment(), $key),
            static fn (string $key) => $client->issuePolicy(self::ORDER_ID, $key),
            static fn (string $key) => $client->cancelOrder(self::ORDER_ID, $key),
            static fn (string $key) => $client->refundPremium(self::ORDER_ID, [], $key),
            static fn (string $key) => $client->createLead(self::leadRequest(), $key),
        ];
        foreach ($writes as $index => $write) {
            foreach (['', '   ', 'short', 'has a space in it', str_repeat('x', 201), "tab\tinside-key"] as $key) {
                try {
                    $write($key);
                    $this->fail("write #{$index} accepted the key " . json_encode($key));
                } catch (ConfigurationException $expected) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        foreach ([
            static fn () => $client->createQuote(['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID], 'short'),
            static fn () => $client->bindPayment(self::ORDER_ID, self::PAYMENT_ID, 'has a space in it'),
        ] as $invoke) {
            try {
                $invoke();
                $this->fail('a malformed optional key was accepted');
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testAnOptionalKeyLeftOutSendsNoHeader(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(201, self::envelope(['insuranceType' => 'TICKET_CANCELLATION', 'schemaVersion' => 1, 'options' => []]))
            ->pushJson(200, self::envelope(self::orderJson()));
        $client->createQuote(self::quoteRequest());
        $this->assertArrayNotHasKey('Idempotency-Key', $http->lastCall()['headers']);
        $client->bindPayment(self::ORDER_ID, self::PAYMENT_ID, '');
        $this->assertArrayNotHasKey('Idempotency-Key', $http->lastCall()['headers']);
    }

    // ── Bodies ────────────────────────────────────────────────────────────────

    public function testCreateQuoteTakesAtMostOneBasketSourceAndKeepsTheTypeObjectAsGiven(): void
    {
        [$client, $http] = $this->make();
        $set = ['insuranceType' => 'TICKET_CANCELLATION', 'schemaVersion' => 1, 'options' => [self::quoteOptionJson()]];
        $http->pushJson(201, self::envelope($set, ['statusCode' => 201]))
            ->pushJson(201, self::envelope($set, ['statusCode' => 201]))
            ->pushJson(201, self::envelope($set, ['statusCode' => 201]));

        $tickets = [['basketItemId' => 'ticket-1', 'transportMode' => 'TRAIN', 'carrier' => 'Sample Rail', 'priceAmount' => 100000]];
        $insured = [['identityType' => 'PASSPORT', 'identityNumber' => 'SAMPLE', 'birthDate' => '2000-01-01']];
        $quote = $client->createQuote([
            'insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'try', 'context' => ['items' => [self::ticketItem()]],
            'insured' => $insured, 'tickets' => $tickets, 'merchantOrderId' => 'ORD-1', 'paymentIntentId' => null,
        ]);
        $this->assertInstanceOf(InsuranceQuoteSet::class, $quote);
        $this->assertSame(self::QUOTE_ID, $quote->options()[0]->quoteId());
        $this->assertSame([
            'insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => [self::ticketItem()->toArray()]],
            'insured' => $insured, 'tickets' => $tickets, 'merchantOrderId' => 'ORD-1',
        ], self::bodyOf($http->calls[0]));
        $this->assertSame('OK', $this->verdict($http->calls[0]));

        $client->createQuote(['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'schemaVersion' => 1]);
        $this->assertSame(
            ['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'schemaVersion' => 1],
            self::bodyOf($http->calls[1])
        );

        // Sold without a basket item: neither source, the type object carries the cover.
        $accident = ['coverageStartDate' => '2026-01-02'];
        $client->createQuote(['insuranceType' => 'PERSONAL_ACCIDENT', 'currency' => 'TRY', 'insured' => $insured, 'accident' => $accident]);
        $this->assertSame(
            ['insuranceType' => 'PERSONAL_ACCIDENT', 'currency' => 'TRY', 'insured' => $insured, 'accident' => $accident],
            self::bodyOf($http->calls[2])
        );

        $invalid = [
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'context' => ['items' => [self::ticketItem()]]], 'not both'],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'teklifId' => 'x'], 'Unknown createQuote request field'],
            [['currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID], 'insuranceType is required'],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TL', 'paymentIntentId' => self::PAYMENT_ID], "'currency'"],
            [['insuranceType' => 'TICKET_CANCELLATION', 'paymentIntentId' => self::PAYMENT_ID], "'currency'"],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => 'pay_1'], "'paymentIntentId'"],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => array_fill(0, 101, self::ticketItem())]], '1 to 100'],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => ['a' => self::ticketItem()]]], '1 to 100'],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => [self::ticketItem()], 'paymentIntentId' => self::PAYMENT_ID]], 'Unknown context field'],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => ['ticket-1']]], 'BasketItem objects or arrays'],
            [['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'merchantOrderId' => str_repeat('m', 129)], 'at most 128'],
        ];
        foreach ($invalid as [$request, $message]) {
            try {
                $client->createQuote($request);
                $this->fail('accepted ' . json_encode(array_keys($request)));
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
        $this->assertSame(3, $http->callCount());
    }

    public function testCreateQuoteWithAPaymentIntentIdAloneSendsNoTypeFields(): void
    {
        [$client, $http] = $this->make();
        $set = ['insuranceType' => 'TICKET_CANCELLATION', 'schemaVersion' => 1, 'options' => [self::quoteOptionJson(['paymentIntentId' => self::PAYMENT_ID])], 'excludedOptions' => []];
        $http->pushJson(201, self::envelope($set, ['statusCode' => 201]))->pushJson(201, self::envelope($set, ['statusCode' => 201]));

        // The insurance data recorded with the payment is merged by Lunixi: nothing but type, currency and payment.
        $quote = $client->createQuote(['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'try', 'paymentIntentId' => self::PAYMENT_ID]);
        $this->assertSame(self::PAYMENT_ID, $quote->options()[0]->paymentIntentId());
        $this->assertStringContainsString('/api/v1/insurance/quotes', $http->calls[0]['url']);
        $this->assertSame(
            ['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID],
            self::bodyOf($http->calls[0])
        );

        // Partial body: only what is missing; the field sent wins over the recorded one.
        $tickets = [['basketItemId' => 'ticket-1', 'ticketNumber' => 'SAMPLE-0002']];
        $client->createQuote(['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'tickets' => $tickets]);
        $this->assertSame(
            ['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'tickets' => $tickets],
            self::bodyOf($http->calls[1])
        );
    }

    public function testContextItemsCarryInsuranceDataUnchanged(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope(['products' => []]))
            ->pushJson(201, self::envelope(['insuranceType' => 'TICKET_CANCELLATION', 'schemaVersion' => 1, 'options' => []], ['statusCode' => 201]));
        // Synthetic values: a made-up passport number and a sample PNR.
        $insuranceData = [
            'insured' => [['identityType' => 'PASSPORT', 'identityNumber' => 'P0000000', 'birthDate' => '2000-01-01']],
            'tickets' => ['pnr' => 'SAMPLE1'],
        ];
        $item = new BasketItem(self::ticketItem()->toArray() + ['insuranceData' => $insuranceData]);

        $client->searchProducts(['currency' => 'TRY', 'context' => ['items' => [$item]]]);
        $client->createQuote(['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => [$item]]]);

        foreach ($http->calls as $call) {
            $this->assertSame($insuranceData, self::bodyOf($call)['context']['items'][0]['insuranceData']);
        }
        $this->assertArrayNotHasKey('tickets', self::bodyOf($http->calls[1]), 'the type object is not copied out of the item');
    }

    public function testCreateQuoteTakesTheTypeObjectsOfTheNewTypes(): void
    {
        [$client, $http] = $this->make();
        foreach (['PET_HEALTH' => 'pet', 'FOREIGN_RESIDENT_HEALTH' => 'residence', 'ABROAD_STAY_HEALTH' => 'abroadStay'] as $type => $field) {
            $http->pushJson(201, self::envelope(['insuranceType' => $type, 'schemaVersion' => 1, 'options' => []], ['statusCode' => 201]));
            $client->createQuote(['insuranceType' => $type, 'currency' => 'TRY', $field => ['startDate' => '2026-01-02']]);
            $this->assertSame(['insuranceType' => $type, 'currency' => 'TRY', $field => ['startDate' => '2026-01-02']], self::bodyOf($http->lastCall()));
        }
    }

    // ── Leads ─────────────────────────────────────────────────────────────────

    public function testCreateLeadSendsTheLeadSchemaFieldsAsGivenAndReadsTheLead(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::leadJson(), ['statusCode' => 201]))
            ->pushJson(201, self::envelope(self::leadJson(['insuranceType' => 'MOTOR_COMPREHENSIVE', 'status' => 'UNKNOWN', 'submittedAt' => null]), ['statusCode' => 201]));

        $lead = $client->createLead(self::leadRequest() + ['schemaVersion' => 1], self::KEY);
        $this->assertInstanceOf(InsuranceLead::class, $lead);
        $this->assertSame(self::LEAD_ID, $lead->leadId());
        $this->assertSame(InsuranceLeadStatus::SUBMITTED, $lead->status());
        $this->assertSame('***', $lead->requestMasked()['note']);
        $this->assertSame(self::leadRequest() + ['schemaVersion' => 1], self::bodyOf($http->calls[0]), 'the type objects and the note are sent unchanged');
        $this->assertSame(self::KEY, $http->calls[0]['headers']['Idempotency-Key']);

        // Trafik / Kasko leads: the person and the vehicle, no shipment.
        $motor = ['insuranceType' => 'MOTOR_COMPREHENSIVE', 'policyholder' => self::leadRequest()['policyholder'], 'vehicle' => self::leadRequest()['vehicle']];
        $unknown = $client->createLead($motor, self::KEY);
        $this->assertSame($motor, self::bodyOf($http->calls[1]));
        $this->assertSame(InsuranceLeadStatus::UNKNOWN, $unknown->status());
        $this->assertNull($unknown->submittedAt());

        $invalid = [
            [self::leadRequest() + ['currency' => 'TRY'], 'Unknown createLead request field'],
            [self::leadRequest() + ['paymentIntentId' => self::PAYMENT_ID], 'Unknown createLead request field'],
            [self::leadRequest() + ['context' => ['items' => [self::ticketItem()]]], 'Unknown createLead request field'],
            [array_diff_key(self::leadRequest(), ['insuranceType' => true]), 'insuranceType is required'],
            [['note' => 42] + self::leadRequest(), "'note' must be a string"],
            [['note' => str_repeat('ş', 1001)] + self::leadRequest(), 'at most 1000'],
        ];
        foreach ($invalid as [$request, $message]) {
            try {
                $client->createLead($request, self::KEY);
                $this->fail('accepted ' . json_encode(array_keys($request)));
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
        $this->assertSame(2, $http->callCount());
    }

    public function testARefusedLeadKeepsItsCodeAndTheLeadId(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(422, ['status' => 'error', 'code' => 'insurance.lead.rejected', 'message' => 'Rejected.', 'statusCode' => 422, 'details' => ['leadId' => self::LEAD_ID]]);
        try {
            $client->createLead(self::leadRequest(), self::KEY);
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertSame('insurance.lead.rejected', $error->getErrorCode());
            $this->assertSame(self::LEAD_ID, ($error->getResponseBody() ?? [])['details']['leadId'] ?? null);
        }
    }

    public function testLeadsAreListedWithTheirFiltersAndReadOneByOne(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::leadJson()], true, 'cursor-2'))
            ->pushJson(200, self::page([self::leadJson(['leadId' => 'insl_99999999999999999999999999999999', 'status' => 'FAILED', 'errorCode' => 'insurance.lead.rejected'])]))
            ->pushJson(200, self::envelope(self::leadJson()));

        $leads = iterator_to_array($client->iterateLeads(['limit' => 1, 'insuranceType' => 'CARGO_TRANSIT', 'status' => InsuranceLeadStatus::SUBMITTED]), false);
        $this->assertSame(['SUBMITTED', 'FAILED'], array_map(static fn (InsuranceLead $lead): string => $lead->status(), $leads));
        $this->assertSame('insurance.lead.rejected', $leads[1]->errorCode());
        $this->assertSame(['limit' => '1', 'insuranceType' => 'CARGO_TRANSIT', 'status' => 'SUBMITTED'], self::queryOf($http->calls[0]));
        $this->assertSame('cursor-2', self::queryOf($http->calls[1])['cursor']);
        $this->assertSame(self::LEAD_ID, $client->getLead(self::LEAD_ID)->leadId());

        foreach ([
            static fn () => $client->listLeads(['status' => 'DONE']),
            static fn () => $client->listLeads(['merchantOrderId' => 'ORD-1']),
            static fn () => $client->getLead(' '),
        ] as $invoke) {
            try {
                $invoke();
                $this->fail('an invalid lead read was sent');
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(3, $http->callCount());
    }

    public function testGetPolicyReceiptAndCertificateReturnThePdfAndAProductWithoutOneIs412(): void
    {
        [$client, $http] = $this->make();
        $http->push(self::binary('%PDF-1.7 receipt', 'application/pdf'))
            ->push(self::binary('%PDF-1.7 certificate', 'application/pdf'))
            ->pushJson(412, ['status' => 'error', 'code' => 'insurance.document.not_supported', 'message' => 'Not supported.', 'statusCode' => 412]);
        $this->assertSame('%PDF-1.7 receipt', $client->getPolicyReceipt(self::POLICY_ID)->bytes());
        $this->assertSame('%PDF-1.7 certificate', $client->getPolicyCertificate(self::POLICY_ID)->bytes());
        try {
            $client->getPolicyCertificate(self::POLICY_ID);
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame(412, $error->getStatusCode());
            $this->assertSame('insurance.document.not_supported', $error->getErrorCode());
        }
        foreach ($http->calls as $call) {
            $this->assertStringStartsWith('application/pdf', $call['headers']['Accept']);
        }
    }

    public function testAQuoteSetCarriesTheInsuranceCompanyAndTheOptionsLeftOut(): void
    {
        [$client, $http] = $this->make();
        $underwriter = ['code' => 'SAMPLE_CO', 'name' => 'Sample Insurance', 'logoUrl' => null, 'disclosureText' => 'Sample text.'];
        $excluded = [
            'underwriter' => ['code' => 'OTHER_CO', 'name' => 'Other Insurance', 'logoUrl' => null, 'disclosureText' => null],
            'provider' => self::providerJson(),
            'errorCode' => 'insurance.validation.invalid',
            'errors' => [['field' => 'insured[0].birthDate', 'reason' => 'required']],
        ];
        $set = [
            'insuranceType' => 'TICKET_CANCELLATION', 'schemaVersion' => 1,
            'options' => [self::quoteOptionJson(['underwriter' => $underwriter]), self::quoteOptionJson(['underwriter' => null])],
            'excludedOptions' => [$excluded],
        ];
        $http->pushJson(201, self::envelope($set, ['statusCode' => 201]));

        $quote = $client->createQuote(['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'context' => ['items' => [self::ticketItem()]]]);
        $company = $quote->options()[0]->underwriter();
        $this->assertInstanceOf(InsuranceUnderwriter::class, $company);
        $this->assertSame('SAMPLE_CO', $company->code());
        $this->assertSame('Sample Insurance', $company->name());
        $this->assertNull($company->logoUrl());
        $this->assertSame('Sample text.', $company->disclosureText());
        $this->assertNull($quote->options()[0]->underwriterName(), 'underwriterName stays next to underwriter');
        $this->assertNull($quote->options()[1]->underwriter(), 'no company reported');

        $left = $quote->excludedOptions();
        $this->assertCount(1, $left);
        $this->assertInstanceOf(InsuranceExcludedQuoteOption::class, $left[0]);
        $this->assertSame('OTHER_CO', $left[0]->underwriter()->code());
        $this->assertSame(self::CONNECTION_ID, $left[0]->provider()->connectionId());
        $this->assertSame('insurance.validation.invalid', $left[0]->errorCode());
        $this->assertSame([['field' => 'insured[0].birthDate', 'reason' => 'required']], $left[0]->errors());
    }

    public function testListProductsWithoutAPaymentAsksForTheTypesSoldWithoutABasketItem(): void
    {
        [$client, $http] = $this->make();
        $product = array_merge(self::productJson(), ['insuranceType' => 'PERSONAL_ACCIDENT', 'typeObject' => 'accident', 'basketItemIds' => []]);
        $http->pushJson(200, self::envelope(['products' => [$product]]));

        $products = $client->listProducts(['currency' => 'try']);
        $this->assertSame([], $products[0]->basketItemIds());
        $this->assertSame('accident', $products[0]->typeObject());
        $this->assertSame(['currency' => 'TRY'], self::queryOf($http->lastCall()));
    }

    public function testAProductReadsPrefillTheMaskedProvidedFieldsQuoteReadyAndTheIssues(): void
    {
        [$client, $http] = $this->make();
        // productOfferToJson (insurance-json.mapper.ts); values synthetic.
        $prefill = ['tickets' => [['basketItemId' => 'ticket-1', 'transportMode' => 'TRAIN', 'departureAt' => '2026-01-02T09:30:00+03:00']]];
        $provided = [['path' => 'insured[0].identityNumber', 'masked' => '***'], ['path' => 'tickets[0].pnr', 'masked' => '***']];
        $issues = [['basketItemId' => 'ticket-2', 'field' => 'device', 'reason' => 'unknown_field']];
        $http->pushJson(200, self::envelope(['products' => [array_merge(self::productJson(), [
            'prefill' => $prefill, 'providedFields' => $provided, 'quoteReady' => true, 'insuranceDataIssues' => $issues,
        ])]]))->pushJson(200, self::envelope(['products' => [self::productJson()]]));

        $product = $client->listProducts(['currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID])[0];
        $this->assertSame($prefill, $product->prefill());
        $this->assertSame($provided, $product->providedFields());
        $this->assertTrue($product->quoteReady());
        $this->assertSame($issues, $product->insuranceDataIssues());

        // An answer without the fields reads as nothing prefilled and not ready.
        $plain = $client->listProducts(['currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID])[0];
        $this->assertSame([], $plain->prefill());
        $this->assertSame([], $plain->providedFields());
        $this->assertFalse($plain->quoteReady());
        $this->assertSame([], $plain->insuranceDataIssues());
    }

    public function testTheReferenceKindsOfTheNewTypesAreListedWithTheirParentCode(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]))->pushJson(200, self::page([]));

        $client->listReferenceValues(InsuranceReferenceKind::PET_BREED, ['parentCode' => 'CAT']);
        $this->assertSame(['parentCode' => 'CAT'], self::queryOf($http->lastCall()));
        $client->listReferenceValues(InsuranceReferenceKind::MEDICAL_PROCEDURE_COVER);
        $this->assertStringContainsString('/api/v1/insurance/reference/MEDICAL_PROCEDURE_COVER', $http->lastCall()['url']);

        $this->expectException(ConfigurationException::class);
        $client->listReferenceValues('ADDRESS_STREET');
    }

    public function testSearchProductsSendsTheItemsAndReadsTheProducts(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope(['products' => [self::productJson()]]))->pushJson(200, self::envelope(['products' => []]));

        $products = $client->searchProducts(['currency' => 'TRY', 'insuranceType' => 'TICKET_CANCELLATION', 'context' => ['items' => [self::ticketItem()]]]);
        $this->assertCount(1, $products);
        $this->assertInstanceOf(InsuranceProduct::class, $products[0]);
        $this->assertSame('tickets', $products[0]->typeObject());
        $this->assertSame(['tr' => 'Bilet iptal', 'en' => 'Ticket cancellation'], $products[0]->title());
        $this->assertSame([['name' => 'tickets']], $products[0]->fields());
        $this->assertSame(
            ['currency' => 'TRY', 'insuranceType' => 'TICKET_CANCELLATION', 'context' => ['items' => [self::ticketItem()->toArray()]]],
            self::bodyOf($http->calls[0])
        );

        $this->assertSame([], $client->listProducts(['currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID]), 'an empty list is not an error');
        $this->assertSame(['currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID], self::queryOf($http->lastCall()));
    }

    public function testAValidationErrorKeepsEveryFieldErrorForTheCaller(): void
    {
        [$client, $http] = $this->make();
        $details = ['errors' => [['field' => 'tickets[0].departureAt', 'reason' => 'required'], ['field' => 'insured[0].birthDate', 'reason' => 'invalid_format']]];
        $http->pushJson(422, ['status' => 'error', 'code' => 'insurance.validation.invalid', 'message' => 'Invalid.', 'statusCode' => 422, 'details' => $details]);
        try {
            $client->createQuote(['insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'tickets' => [['basketItemId' => 'ticket-1']]]);
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertSame('insurance.validation.invalid', $error->getErrorCode());
            $this->assertSame($details, ($error->getResponseBody() ?? [])['details'] ?? null);
        }
    }

    public function testCreateOrderSendsTheConsentAndConsentFromQuoteCopiesTheTermsUnchanged(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::orderJson(), ['statusCode' => 201]));

        $acceptedAt = new DateTimeImmutable('2026-01-01 10:01:00.250', new DateTimeZone('Europe/Istanbul'));
        $consent = InsuranceClient::consentFromQuote(new InsuranceQuoteOption(self::quoteOptionJson()), $acceptedAt);
        $this->assertSame(['termsVersion' => 'v1', 'termsSha256' => str_repeat('d', 64), 'acceptedAt' => '2026-01-01T07:01:00.250Z'], $consent);
        $this->assertSame($consent, InsuranceClient::consentFromQuote(self::quoteOptionJson(), $acceptedAt), 'the array form reads the same');

        $order = $client->createOrder(['quoteId' => self::QUOTE_ID, 'merchantOrderId' => 'ORD-1', 'consent' => $consent], self::KEY);
        $this->assertSame(self::ORDER_ID, $order->orderId());
        $this->assertSame(['quoteId' => self::QUOTE_ID, 'merchantOrderId' => 'ORD-1', 'consent' => $consent], self::bodyOf($http->calls[0]));

        $invalid = [
            [['quoteId' => self::QUOTE_ID], 'consent must be'],
            [['quoteId' => self::QUOTE_ID, 'consent' => ['termsVersion' => 'v1', 'termsSha256' => str_repeat('d', 64)]], 'consent.acceptedAt'],
            [['quoteId' => self::QUOTE_ID, 'consent' => self::consent() + ['ip' => '127.0.0.1']], 'Unknown consent field'],
            [['consent' => self::consent()], 'quoteId is required'],
            [['quoteId' => self::QUOTE_ID, 'consent' => self::consent(), 'paymentIntentId' => self::PAYMENT_ID], 'Unknown createOrder request field'],
        ];
        foreach ($invalid as [$request, $message]) {
            try {
                $client->createOrder($request, self::KEY);
                $this->fail('accepted ' . json_encode(array_keys($request)));
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }

        try {
            InsuranceClient::consentFromQuote(['quoteId' => self::QUOTE_ID], $acceptedAt);
            $this->fail('an option without terms was accepted');
        } catch (ConfigurationException $expected) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $http->callCount());
    }

    public function testPremiumBasketItemIsTheVirtualPremiumLineOfAMerchantOrder(): void
    {
        $item = InsuranceClient::premiumBasketItem(new InsuranceOrder(self::orderJson()), 'Ticket cancellation insurance', 'insurance');
        $this->assertSame([
            'id' => self::ORDER_ID, 'price' => 4900, 'name' => 'Ticket cancellation insurance', 'category1' => 'insurance', 'itemType' => 'VIRTUAL',
        ], $item->toArray());
        $this->assertSame([], array_filter(array_keys($item->toArray()), static fn (string $key): bool => str_starts_with($key, 'insurance')));
        $this->assertSame('x', InsuranceClient::premiumBasketItem(self::orderJson(), 'n', 'c', 'x')->toArray()['category2']);

        foreach ([
            [self::orderJson(['premiumCollectedBy' => 'PROVIDER']), 'Insurance', 'insurance', 'premiumCollectedBy MERCHANT'],
            [self::orderJson(), '', 'insurance', 'name is required'],
            [self::orderJson(['premiumAmount' => 0]), 'Insurance', 'insurance', 'premiumAmount'],
            [self::orderJson(['premiumAmount' => '4900']), 'Insurance', 'insurance', 'premiumAmount'],
        ] as [$order, $name, $category, $message]) {
            try {
                InsuranceClient::premiumBasketItem($order, $name, $category);
                $this->fail('accepted ' . $message);
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testBindConfirmRefundAndIssueSendTheDtoBodies(): void
    {
        [$client, $http] = $this->make();
        for ($i = 0; $i < 5; $i++) {
            $http->pushJson(200, self::envelope(self::orderJson()));
        }

        $client->bindPayment(self::ORDER_ID, self::PAYMENT_ID);
        $client->confirmPayment(self::ORDER_ID, [
            'externalPaymentId' => 'pay-1', 'paidAmount' => 4900, 'paidCurrency' => 'try',
            'paidAt' => new DateTimeImmutable('2026-01-01T07:02:00.000Z'), 'paymentProvider' => 'Own POS',
        ], self::KEY);
        $client->refundPremium(self::ORDER_ID, [], self::KEY);
        $client->refundPremium(self::ORDER_ID, ['externalRefund' => ['externalRefundId' => 'refund-1', 'refundedAt' => '2026-01-02T07:00:00+03:00']], self::KEY);
        $client->issuePolicy(self::ORDER_ID, self::KEY);

        $this->assertSame(['paymentIntentId' => self::PAYMENT_ID], self::bodyOf($http->calls[0]));
        $this->assertSame(['payment' => [
            'externalPaymentId' => 'pay-1', 'paidAmount' => 4900, 'paidCurrency' => 'TRY', 'paidAt' => '2026-01-01T07:02:00.000Z', 'paymentProvider' => 'Own POS',
        ]], self::bodyOf($http->calls[1]));
        $this->assertSame('{}', $http->calls[2]['body'], 'a Lunixi-payment refund sends the empty JSON object');
        $this->assertSame(['externalRefund' => ['externalRefundId' => 'refund-1', 'refundedAt' => '2026-01-02T07:00:00+03:00']], self::bodyOf($http->calls[3]));
        $this->assertNull($http->calls[4]['body'], 'issue-policy has no body');
        foreach ($http->calls as $call) {
            $this->assertSame('OK', $this->verdict($call));
        }

        $invalid = [
            static fn () => $client->confirmPayment(self::ORDER_ID, ['paidAmount' => 49.5] + self::externalPayment(), self::KEY),
            static fn () => $client->confirmPayment(self::ORDER_ID, array_diff_key(self::externalPayment(), ['paidAt' => true]), self::KEY),
            static fn () => $client->confirmPayment(self::ORDER_ID, self::externalPayment() + ['cardLast4' => '0000'], self::KEY),
            static fn () => $client->refundPremium(self::ORDER_ID, ['externalRefund' => ['externalRefundId' => 'refund-1']], self::KEY),
            static fn () => $client->refundPremium(self::ORDER_ID, ['reason' => 'x'], self::KEY),
            static fn () => $client->bindPayment(self::ORDER_ID, 'not-a-uuid'),
            static fn () => $client->getOrder('  '),
        ];
        foreach ($invalid as $index => $invoke) {
            try {
                $invoke();
                $this->fail("invalid call #{$index} was sent");
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(5, $http->callCount());
    }

    // ── Filters → query ───────────────────────────────────────────────────────

    public function testQueryFiltersAreSentTheWayTheGatewayParsesThem(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]))->pushJson(200, self::page([]))->pushJson(200, self::page([]));

        $client->listOrders(['limit' => 50, 'cursor' => 'opaque', 'paymentIntentId' => self::PAYMENT_ID, 'merchantOrderId' => 'ORD-1']);
        $client->listReconciliationOrders([
            'from' => new DateTimeImmutable('2026-01-01T00:00:00+03:00'), 'to' => '2026-02-01T00:00:00+03:00', 'connectionId' => self::CONNECTION_ID,
            'insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'try', 'limit' => 100,
        ]);
        $client->listReferenceValues(InsuranceReferenceKind::DEVICE_MODEL, ['parentCode' => 'BRAND-1', 'limit' => 25]);

        $this->assertSame(['limit' => '50', 'cursor' => 'opaque', 'paymentIntentId' => self::PAYMENT_ID, 'merchantOrderId' => 'ORD-1'], self::queryOf($http->calls[0]));
        $this->assertSame([
            'from' => '2025-12-31T21:00:00.000Z', 'to' => '2026-02-01T00:00:00+03:00', 'connectionId' => self::CONNECTION_ID,
            'insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'limit' => '100',
        ], self::queryOf($http->calls[1]));
        $this->assertStringContainsString('to=2026-02-01T00%3A00%3A00%2B03%3A00', $http->calls[1]['url'], 'the "+" of an offset is percent-encoded');
        $this->assertSame('OK', $this->verdict($http->calls[1]));
        $this->assertSame(['parentCode' => 'BRAND-1', 'limit' => '25'], self::queryOf($http->calls[2]));
    }

    /** @return array<string,array{0:string,1:array<int,mixed>,2:string}> */
    public static function invalidFilters(): array
    {
        $period = self::RECONCILIATION;

        return [
            'unknown order filter' => ['listOrders', [['status' => 'ACTIVE']], 'Unknown insurance filter'],
            'limit 0' => ['listOrders', [['limit' => 0]], "'limit'"],
            'limit 101' => ['listOrders', [['limit' => 101]], "'limit'"],
            'limit as string' => ['listOrders', [['limit' => '50']], "'limit'"],
            'long cursor' => ['listOrders', [['cursor' => str_repeat('x', 513)]], "'cursor'"],
            'products without currency' => ['listProducts', [['paymentIntentId' => self::PAYMENT_ID]], "'currency'"],
            'products by order number' => ['listProducts', [['currency' => 'TRY', 'paymentIntentId' => self::PAYMENT_ID, 'merchantOrderId' => 'ORD-1']], 'Unknown insurance filter'],
            'summary without to' => ['getReconciliationSummary', [['from' => $period['from']]], "'to'"],
            'summary with limit' => ['getReconciliationSummary', [$period + ['limit' => 10]], 'Unknown insurance filter'],
            'connection not a uuid' => ['listReconciliationOrders', [$period + ['connectionId' => 'conn_1']], "'connectionId'"],
            'export without from' => ['exportReconciliation', [['to' => $period['to']]], "'from'"],
            'unknown reference kind' => ['listReferenceValues', ['NEIGHBOURHOOD'], 'Reference kind'],
            'reference with kind filter' => ['listReferenceValues', ['CITY', ['kind' => 'CITY']], 'Unknown insurance filter'],
            'search without items' => ['searchProducts', [['currency' => 'TRY']], "'context.items' is required"],
            'search with payment' => ['searchProducts', [['currency' => 'TRY', 'context' => ['items' => [self::ticketItem()]], 'paymentIntentId' => self::PAYMENT_ID]], 'Unknown searchProducts request field'],
        ];
    }

    /** @param array<int,mixed> $args */
    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersFailBeforeAnythingIsSent(string $method, array $args, string $message): void
    {
        [$client, $http] = $this->make();
        try {
            $client->{$method}(...$args);
            $this->fail("{$method} was sent");
        } catch (ConfigurationException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
        $this->assertSame(0, $http->callCount());
    }

    // ── Results ───────────────────────────────────────────────────────────────

    public function testPagesReadTheTopLevelPageInfoAndIterateFollowsTheCursor(): void
    {
        [$client, $http] = $this->make();
        $second = self::orderJson(['orderId' => 'inso_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee']);
        $http->pushJson(200, self::page([self::orderJson()], true, 'cursor-2'))->pushJson(200, self::page([$second], true, null));

        $ids = [];
        foreach ($client->iterateOrders(['limit' => 1]) as $order) {
            $this->assertInstanceOf(InsuranceOrder::class, $order);
            $ids[] = $order->orderId();
        }
        $this->assertSame([self::ORDER_ID, $second['orderId']], $ids, 'a page without a cursor is the last one');
        $this->assertSame(['limit' => '1', 'cursor' => 'cursor-2'], self::queryOf($http->calls[1]));
    }

    public function testAnOrderReadsItsAxesPoliciesAndProvider(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::orderJson([
            'status' => 'ACTIVE', 'collectionStatus' => 'COLLECTED', 'policyStatus' => 'ISSUED', 'documentStatus' => 'AVAILABLE',
            'paymentMode' => 'LUNIXI_PAYMENT', 'paymentIntentId' => self::PAYMENT_ID,
            'policies' => [['policyId' => self::POLICY_ID, 'basketItemId' => 'ticket-1', 'policyNumber' => 'P-1', 'policyStatus' => 'ISSUED', 'documentStatus' => 'AVAILABLE']],
        ])));
        $order = $client->getOrder(self::ORDER_ID);
        $this->assertSame(InsuranceOrderStatus::ACTIVE, $order->status());
        $this->assertSame(InsurancePaymentMode::LUNIXI_PAYMENT, $order->paymentMode());
        $this->assertSame(4900, $order->premiumAmount());
        $this->assertSame(self::PAYMENT_ID, $order->paymentIntentId());
        $this->assertSame(self::CONNECTION_ID, $order->provider()->connectionId());
        $this->assertNull($order->provider()->logoUrl());
        $this->assertNull($order->errorCode(), 'a null field reads as null');
        $this->assertSame('P-1', $order->policies()[0]->policyNumber());
        $this->assertSame(['ticket-1'], $order->basketItemIds());
    }

    public function testGetPolicyDocumentReturnsThePdfAndAsksForAPdf(): void
    {
        [$client, $http] = $this->make();
        $http->push(self::binary('%PDF-1.7 sample', 'application/pdf'));
        $document = $client->getPolicyDocument(self::POLICY_ID);
        $this->assertSame('application/pdf', $document->contentType());
        $this->assertSame('%PDF-1.7 sample', $document->bytes());
        $this->assertStringStartsWith('application/pdf', $http->lastCall()['headers']['Accept']);
    }

    public function testADocumentErrorIsTheJsonEnvelopeWithItsCode(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(503, ['status' => 'error', 'code' => 'insurance.document.unavailable', 'message' => 'Retry later.', 'statusCode' => 503, 'details' => ['retryable' => true]]);
        try {
            $client->getPolicyDocument(self::POLICY_ID);
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertSame('insurance.document.unavailable', $error->getErrorCode());
        }
    }

    public function testExportReconciliationReadsTheContinuationAndAbortLines(): void
    {
        [$client, $http] = $this->make();
        $header = "\u{FEFF}orderId,merchantOrderId\r\n";
        $row = self::ORDER_ID . ",ORD-1\r\n";
        $http->push(self::binary($header . $row, 'text/csv; charset=utf-8'))
            ->push(self::binary($header . $row . "# truncated=true orderCap=20000 nextCursor=opaque-next\r\n", 'text/csv; charset=utf-8'))
            ->push(self::binary($header . $row . "# export-aborted=true\r\n", 'text/csv; charset=utf-8'));

        $complete = $client->exportReconciliation(self::RECONCILIATION);
        $this->assertSame([false, null, false], [$complete->truncated(), $complete->nextCursor(), $complete->aborted()]);
        $this->assertSame('text/csv; charset=utf-8', $complete->contentType());
        $this->assertSame($header . $row, $complete->bytes());

        $cut = $client->exportReconciliation(self::RECONCILIATION);
        $this->assertSame([true, 'opaque-next', false], [$cut->truncated(), $cut->nextCursor(), $cut->aborted()]);

        $aborted = $client->exportReconciliation(self::RECONCILIATION + ['cursor' => 'opaque-next']);
        $this->assertSame([false, null, true], [$aborted->truncated(), $aborted->nextCursor(), $aborted->aborted()]);
        $this->assertSame('opaque-next', self::queryOf($http->calls[2])['cursor']);
        $this->assertStringStartsWith('text/csv', $http->calls[0]['headers']['Accept']);
    }

    public function testTheReconciliationSummaryReadsGroupsAndTotals(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope([
            'period' => ['from' => '2026-01-01T00:00:00.000Z', 'to' => '2026-02-01T00:00:00.000Z'],
            'filters' => ['connectionId' => null, 'insuranceType' => 'TICKET_CANCELLATION', 'currency' => null],
            'groups' => [['provider' => self::providerJson(), 'currency' => 'TRY', 'premiumCollectedBy' => 'MERCHANT', 'totals' => self::totalsJson()]],
            'generatedAt' => '2026-02-01T08:00:00.000Z',
        ]));
        $summary = $client->getReconciliationSummary(self::RECONCILIATION);
        $this->assertSame(['from' => '2026-01-01T00:00:00.000Z', 'to' => '2026-02-01T00:00:00.000Z'], $summary->period());
        $this->assertSame(['connectionId' => null, 'insuranceType' => 'TICKET_CANCELLATION', 'currency' => null], $summary->filters());
        $group = $summary->groups()[0];
        $this->assertSame('Sample', $group->provider()->name());
        $this->assertSame(9800, $group->totals()->collectedPremiumAmount());
        $this->assertSame(1, $group->totals()->inForcePolicyCount());
    }

    public function testAConflictKeepsItsCodeAndReason(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(409, ['status' => 'error', 'code' => 'insurance.order.premium_item_mismatch', 'message' => 'Mismatch.', 'statusCode' => 409, 'details' => ['reason' => 'missing']]);
        try {
            $client->bindPayment(self::ORDER_ID, self::PAYMENT_ID);
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame('insurance.order.premium_item_mismatch', $error->getErrorCode());
            $this->assertSame('missing', ($error->getResponseBody() ?? [])['details']['reason'] ?? null);
        }
    }

    // ── Field lock: accessors = gateway fields ────────────────────────────────

    /** @return array<string,array{0:class-string,1:string[]}> */
    public static function responseFields(): array
    {
        // insurance-json.mapper.ts (productOfferToJson without formSchema, referenceValueToJson,
        // quoteOptionToJson without quoteToken, quoteSetToJson, orderToJson),
        // insurance-reconciliation.mapper.ts, insurance-webhook.interface.ts (InsuranceWebhookPayload).
        return [
            'provider' => [InsuranceProvider::class, ['code', 'name', 'logoUrl', 'connectionId']],
            'product' => [InsuranceProduct::class, [
                'insuranceType', 'typeObject', 'schemaVersion', 'basketItemIds', 'title', 'description', 'fields',
                'prefill', 'providedFields', 'quoteReady', 'insuranceDataIssues',
            ]],
            'reference value' => [InsuranceReferenceValue::class, ['code', 'label', 'parentCode']],
            'underwriter' => [InsuranceUnderwriter::class, ['code', 'name', 'logoUrl', 'disclosureText']],
            'excluded option' => [InsuranceExcludedQuoteOption::class, ['underwriter', 'provider', 'errorCode', 'errors']],
            'terms' => [InsuranceTerms::class, ['version', 'sha256']],
            'quote option' => [InsuranceQuoteOption::class, [
                'quoteId', 'insuranceType', 'schemaVersion', 'title', 'premiumAmount', 'currency', 'premiumCollectedBy', 'provider',
                'basketItemIds', 'coverageSummary', 'terms', 'underwriterName', 'underwriter', 'expiresAt', 'status', 'paymentIntentId',
                'createdAt',
            ]],
            'quote set' => [InsuranceQuoteSet::class, ['insuranceType', 'schemaVersion', 'options', 'excludedOptions']],
            'order' => [InsuranceOrder::class, [
                'orderId', 'quoteId', 'insuranceType', 'surface', 'status', 'collectionStatus', 'policyStatus', 'refundStatus',
                'documentStatus', 'paymentMode', 'premiumCollectedBy', 'premiumAmount', 'currency', 'paymentIntentId', 'merchantOrderId',
                'provider', 'basketItemIds', 'policies', 'errorCode', 'refundErrorCode', 'refundedAt', 'createdAt', 'updatedAt',
            ]],
            'order policy' => [InsuranceOrderPolicy::class, ['policyId', 'basketItemId', 'policyNumber', 'policyStatus', 'documentStatus']],
            'summary' => [InsuranceReconciliationSummary::class, ['period', 'filters', 'groups', 'generatedAt']],
            'group' => [InsuranceReconciliationGroup::class, ['provider', 'currency', 'premiumCollectedBy', 'totals']],
            'totals' => [InsuranceReconciliationTotals::class, array_keys(self::totalsJson())],
            'reconciliation order' => [InsuranceReconciliationOrder::class, array_keys(self::reconciliationOrderJson())],
            'reconciliation policy' => [InsuranceReconciliationPolicy::class, ['policyId', 'basketItemId', 'policyNumber', 'policyStatus', 'premiumAmount', 'cancelledAt']],
            'webhook' => [InsuranceWebhookData::class, [
                'eventId', 'eventType', 'merchantId', 'insuranceOrderId', 'paymentIntentId', 'merchantOrderId', 'insuranceType',
                'premiumCollectedBy', 'provider', 'status', 'collectionStatus', 'policyStatus', 'refundStatus', 'documentStatus',
                'premiumAmount', 'currency', 'policies', 'errorCode', 'createdAt',
            ]],
            'webhook policy' => [InsuranceWebhookPolicy::class, ['policyId', 'basketItemId', 'policyNumber', 'policyStatus', 'documentStatus', 'documentUrl']],
            // insurance-json.mapper.ts leadToJson; insurance-webhook.interface.ts InsuranceLeadWebhookPayload, InsuranceWebhookLead.
            'lead' => [InsuranceLead::class, array_keys(self::leadJson())],
            'lead webhook' => [InsuranceLeadWebhookData::class, ['eventId', 'eventType', 'merchantId', 'lead', 'createdAt']],
            'webhook lead' => [InsuranceWebhookLead::class, ['leadId', 'insuranceType', 'schemaVersion', 'status', 'submittedAt', 'createdAt']],
        ];
    }

    /**
     * @param class-string $class
     * @param string[] $fields
     */
    #[DataProvider('responseFields')]
    public function testOneAccessorPerGatewayField(string $class, array $fields): void
    {
        // Readers that add no field of their own.
        $derived = ['get', 'raw', 'environment', 'fromEvent'];
        $accessors = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || in_array($method->getName(), $derived, true)) {
                continue;
            }
            $accessors[] = $method->getName();
        }
        sort($accessors);
        sort($fields);
        $this->assertSame($fields, $accessors, "{$class} must expose exactly the gateway's fields");
        $this->assertTrue(is_subclass_of($class, InsuranceResource::class));
    }

    public function testValueSetsMatchTheGateway(): void
    {
        $this->assertSame(['MERCHANT', 'PROVIDER'], InsurancePremiumCollectedBy::ALL);
        $this->assertSame(['REQUESTED', 'QUOTED', 'EXPIRED', 'REJECTED', 'ERROR'], InsuranceQuoteStatus::ALL);
        $statuses = InsuranceOrderStatus::ALL;
        sort($statuses);
        $this->assertSame(['ACTION_REQUIRED', 'ACTIVE', 'CANCELLED', 'CREATED', 'FAILED', 'PARTIALLY_ACTIVE', 'PROCESSING', 'REFUNDED'], $statuses);
        $this->assertSame(['NOT_STARTED', 'PENDING', 'ACTION_REQUIRED', 'COLLECTED', 'FAILED', 'UNKNOWN'], InsuranceCollectionStatus::ALL);
        $this->assertSame([
            'NOT_REQUESTED', 'PENDING', 'PARTIALLY_ISSUED', 'ISSUED', 'FAILED_RETRYABLE', 'FAILED_FINAL', 'UNKNOWN', 'CANCEL_PENDING', 'CANCELLED',
        ], InsurancePolicyStatus::ALL);
        $this->assertSame(['NONE', 'REQUIRED', 'PENDING', 'REFUNDED', 'FAILED'], InsuranceRefundStatus::ALL);
        $this->assertSame(['NOT_AVAILABLE', 'PENDING', 'AVAILABLE', 'FAILED'], InsuranceDocumentStatus::ALL);
        $this->assertSame(['LUNIXI_PAYMENT', 'EXTERNAL_PAYMENT', 'NO_PAYMENT'], InsurancePaymentMode::ALL);
        $this->assertSame(['MERCHANT_API', 'CHECKOUT_FORM', 'PANEL'], InsuranceOrderSurface::ALL);
        $this->assertSame([
            'COUNTRY', 'CITY', 'DISTRICT', 'DEVICE_BRAND', 'DEVICE_MODEL', 'VEHICLE_BRAND', 'VEHICLE_MODEL', 'VEHICLE_TYPE', 'PRODUCT_CATEGORY',
            'VEHICLE_USAGE', 'LIABILITY_LIMIT', 'OCCUPATION', 'PET_BREED', 'DWELLING_TYPE', 'DWELLING_FLOOR', 'BANK', 'BANK_BRANCH',
            'FINANCE_INSTITUTION', 'MEDICAL_PROCEDURE_COVER',
        ], InsuranceReferenceKind::ALL);
        $orderEvents = [
            'insurance.policy.issued', 'insurance.policy.failed', 'insurance.refund.required',
            'insurance.policy.document_ready', 'insurance.policy.cancelled', 'insurance.refund.completed',
        ];
        $this->assertSame($orderEvents, InsuranceEvents::ORDER_EVENTS);
        $this->assertSame(['insurance.lead.submitted'], InsuranceEvents::LEAD_EVENTS);
        $this->assertSame(array_merge($orderEvents, ['insurance.lead.submitted']), InsuranceEvents::ALL);
        $this->assertSame(['PENDING', 'SUBMITTED', 'UNKNOWN', 'FAILED'], InsuranceLeadStatus::ALL);
    }

    public function testFieldListsMatchTheGatewayDtos(): void
    {
        $this->assertSame([
            'listProducts' => ['currency', 'paymentIntentId', 'insuranceType'],
            'listReferenceValues' => ['parentCode', 'limit', 'cursor'],
            'listOrders' => ['limit', 'cursor', 'paymentIntentId', 'merchantOrderId'],
            'getReconciliationSummary' => ['from', 'to', 'connectionId', 'insuranceType', 'currency'],
            'listReconciliationOrders' => ['from', 'to', 'connectionId', 'insuranceType', 'currency', 'limit', 'cursor'],
            'exportReconciliation' => ['from', 'to', 'connectionId', 'insuranceType', 'currency', 'cursor'],
            'listLeads' => ['limit', 'cursor', 'insuranceType', 'status'],
        ], InsuranceClient::FILTERS);
        $typeObjects = [
            'abroadStay', 'accident', 'device', 'event', 'instrument', 'pet', 'procedure', 'product', 'rental', 'residence', 'stay', 'tickets',
            'trip', 'vehicle',
        ];
        $quoteFields = InsuranceClient::QUOTE_FIELDS;
        sort($quoteFields);
        $expected = array_merge($typeObjects, ['context', 'currency', 'insuranceType', 'insured', 'merchantOrderId', 'paymentIntentId', 'policyholder', 'schemaVersion']);
        sort($expected);
        $this->assertSame($expected, $quoteFields);
        // CreateInsuranceLeadDto: the type body (InsuranceQuotePayloadDto) + note, shipment, company.
        $leadFields = InsuranceClient::LEAD_FIELDS;
        sort($leadFields);
        $expected = array_merge($typeObjects, ['company', 'insuranceType', 'insured', 'note', 'policyholder', 'schemaVersion', 'shipment']);
        sort($expected);
        $this->assertSame($expected, $leadFields);
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    public function testAnInsuranceWebhookVerifiesAndReads(): void
    {
        $secret = 'whsec_test_insurance';
        $eventId = '9e8d7c6b-5a4f-4e3d-8c2b-1a0f9e8d7c6b';
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $data = [
            'eventId' => $eventId, 'eventType' => 'insurance.policy.failed', 'merchantId' => 'org_1', 'insuranceOrderId' => self::ORDER_ID,
            'paymentIntentId' => self::PAYMENT_ID, 'merchantOrderId' => 'ORD-1', 'insuranceType' => 'TICKET_CANCELLATION',
            'premiumCollectedBy' => 'MERCHANT', 'provider' => self::providerJson(), 'status' => 'FAILED', 'collectionStatus' => 'COLLECTED',
            'policyStatus' => 'FAILED_FINAL', 'refundStatus' => 'REQUIRED', 'documentStatus' => 'NOT_AVAILABLE', 'premiumAmount' => 4900,
            'currency' => 'TRY',
            'policies' => [[
                'policyId' => self::POLICY_ID, 'basketItemId' => 'ticket-1', 'policyNumber' => null, 'policyStatus' => 'FAILED_FINAL',
                'documentStatus' => 'NOT_AVAILABLE', 'documentUrl' => '',
            ]],
            'errorCode' => 'insurance.policy.issue_failed', 'createdAt' => $timestamp,
        ];
        // The delivery envelope webhook-service builds, `data` = the payload.
        $body = (string) json_encode([
            'id' => $eventId, 'type' => 'insurance.policy.failed', 'sourceService' => 'insurance-service', 'organizationId' => 'org_1',
            'productCode' => 'INSURANCE_SERVICE', 'environment' => 'TEST', 'eventVersion' => 1, 'schemaVersion' => 'v1',
            'createdAt' => $timestamp, 'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'x-lunixi-event-id' => $eventId,
            'x-lunixi-event-type' => 'insurance.policy.failed',
            'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $body, $secret),
            'x-lunixi-signature-timestamp' => $timestamp,
        ];

        $event = (new WebhookVerifier())->verify($body, $headers, $secret);
        $this->assertSame(InsuranceEvents::POLICY_FAILED, $event->type());
        $this->assertTrue($event->matches(InsuranceEvents::ALL_EVENTS));

        $order = InsuranceWebhookData::fromEvent($event);
        $this->assertSame('TEST', $order->environment());
        $this->assertSame(self::ORDER_ID, $order->insuranceOrderId());
        $this->assertSame('insurance.policy.issue_failed', $order->errorCode());
        $this->assertSame(InsuranceRefundStatus::REQUIRED, $order->refundStatus());
        $this->assertNull($order->policies()[0]->documentUrl(), 'an empty documentUrl reads as null');
        $this->assertNull($order->policies()[0]->policyNumber());
    }

    public function testFromEventRefusesAnotherEventType(): void
    {
        $this->expectException(ConfigurationException::class);
        InsuranceWebhookData::fromEvent(new WebhookEvent('evt_1', 'payment.captured', '2026-01-01T00:00:00Z', ['intentId' => 'pi_1']));
    }

    public function testALeadWebhookVerifiesAndReadsOnlyAsALead(): void
    {
        $secret = 'whsec_test_insurance';
        $eventId = '8d7c6b5a-4f3e-4d2c-9b1a-0f9e8d7c6b5a';
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $data = [
            'eventId' => $eventId, 'eventType' => 'insurance.lead.submitted', 'merchantId' => 'org_1',
            'lead' => [
                'leadId' => self::LEAD_ID, 'insuranceType' => 'CARGO_TRANSIT', 'schemaVersion' => 1, 'status' => 'SUBMITTED',
                'submittedAt' => $timestamp, 'createdAt' => $timestamp,
            ],
            'createdAt' => $timestamp,
        ];
        $body = (string) json_encode([
            'id' => $eventId, 'type' => 'insurance.lead.submitted', 'sourceService' => 'insurance-service', 'organizationId' => 'org_1',
            'productCode' => 'INSURANCE_SERVICE', 'environment' => 'LIVE', 'eventVersion' => 1, 'schemaVersion' => 'v1',
            'createdAt' => $timestamp, 'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'x-lunixi-event-id' => $eventId,
            'x-lunixi-event-type' => 'insurance.lead.submitted',
            'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $body, $secret),
            'x-lunixi-signature-timestamp' => $timestamp,
        ];

        $event = (new WebhookVerifier())->verify($body, $headers, $secret);
        $this->assertSame(InsuranceEvents::LEAD_SUBMITTED, $event->type());
        $this->assertTrue($event->matches(InsuranceEvents::ALL_EVENTS));

        $read = InsuranceLeadWebhookData::fromEvent($event);
        $this->assertSame('LIVE', $read->environment());
        $this->assertSame(self::LEAD_ID, $read->lead()->leadId());
        $this->assertSame(InsuranceLeadStatus::SUBMITTED, $read->lead()->status());
        $this->assertSame(1, $read->lead()->schemaVersion());

        try {
            InsuranceWebhookData::fromEvent($event);
            $this->fail('a lead event was read as an order');
        } catch (ConfigurationException $expected) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(ConfigurationException::class);
        InsuranceLeadWebhookData::fromEvent(new WebhookEvent('evt_1', InsuranceEvents::POLICY_ISSUED, '2026-01-01T00:00:00Z', []));
    }
}
