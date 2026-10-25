<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\BankConnect;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\BankConnect\BankConnectClient;
use Lunixi\Sdk\BankConnect\BankConnectResource;
use Lunixi\Sdk\BankConnect\BankFxClient;
use Lunixi\Sdk\BankConnect\BankFxOrder;
use Lunixi\Sdk\BankConnect\BankFxOrderStatus;
use Lunixi\Sdk\BankConnect\BankFxQuote;
use Lunixi\Sdk\BankConnect\BankFxRate;
use Lunixi\Sdk\BankConnect\BankFxRequestedSide;
use Lunixi\Sdk\BankConnect\BankTransfer;
use Lunixi\Sdk\BankConnect\BankTransferClient;
use Lunixi\Sdk\BankConnect\BankTransferEvent;
use Lunixi\Sdk\BankConnect\BankTransferEvents;
use Lunixi\Sdk\BankConnect\BankTransferInitiatedVia;
use Lunixi\Sdk\BankConnect\BankTransferPurpose;
use Lunixi\Sdk\BankConnect\BankTransferRail;
use Lunixi\Sdk\BankConnect\BankTransferRoutes;
use Lunixi\Sdk\BankConnect\BankTransferStatus;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\SignatureGuardStandIn;
use Lunixi\Sdk\Webhook\WebhookEvent;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract source: nest-payment-gateway apps/api-gateway/src/bank-connect/transfers/
 * bank-connect-transfer-api.controller.ts, bank-connect-transfer-gateway.service.ts,
 * dtos/bank-connect-transfer.dto.ts, mappers/bank-connect-transfer.mapper.ts; webhook
 * payload apps/bank-connect-service/src/transfers/transfer-events.ts.
 */
final class BankTransferClientTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';
    private const CONNECTION_ID = '1c0e3a5f-2d4b-4f6a-8b8c-3d5e7f9a1b2c';
    private const ACCOUNT_ID = '2d1f4b6a-3e5c-4a7b-9c9d-4e6f8a0b2c3d';
    private const USD_ACCOUNT_ID = '3e2a5c7b-4f6d-4b8c-8dae-5f7a9b1c3d4e';
    private const TRANSFER_ID = '5a4c7e9d-6b8f-4dae-8fc0-7b9c1d3e5f60';
    private const QUOTE_ID = '6b5d8fae-7c9a-4ebf-90d1-8cad2e4f6a71';
    private const ORDER_ID = '7c6e9abf-8dab-4fc0-81e2-9dbe3f5a7b82';

    private string $publicKey = '';

    /** @return array{0:BankConnectClient,1:FakeHttpClient} */
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

        return [new BankConnectClient(new ApiClient($config, $signer, new TokenManager($config, $signer, $http, $store), $http)), $http];
    }

    /** @return array<string,mixed> */
    private static function envelope(array $data, array $extra = []): array
    {
        return array_merge(['status' => 'success', 'code' => 'SUCCESS', 'data' => $data, 'requestId' => 'req_1'], $extra);
    }

    private static function page(array $items): array
    {
        return self::envelope(['items' => $items], ['pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => null]]);
    }

    /** @param array{url:string} $call */
    private static function target(array $call): string
    {
        return substr($call['url'], strlen(self::BASE_URL));
    }

    // ── Fixtures: exactly the fields bank-connect-transfer.mapper.ts emits ────

    private static function transferJson(array $overrides = []): array
    {
        return array_merge([
            'id' => self::TRANSFER_ID, 'connectionId' => self::CONNECTION_ID, 'accountId' => self::ACCOUNT_ID, 'environment' => 'test',
            'status' => 'SUBMITTED', 'rail' => null, 'amountMinor' => 150075, 'currency' => 'TRY',
            'sourceIbanMasked' => 'TR00 **** **** **** **** **00 00', 'receiverIbanMasked' => 'TR00 **** **** **** **** **01 46',
            'receiverNameMasked' => 'Ö**** T*******', 'receiverBankCode' => '0062', 'paymentPurpose' => null, 'description' => 'Fatura 2026-0042',
            'idempotencyKey' => 'payout-2026-0042', 'failureCode' => null, 'outcomeUnknown' => false, 'initiatedVia' => 'API',
            'sanctionStatus' => 'NOT_SCREENED', 'bankTransactionId' => null, 'bankState' => null,
            'createdAt' => '2026-01-01T07:00:00.000Z', 'submittedAt' => '2026-01-01T07:00:00.000Z', 'completedAt' => null,
            'failedAt' => null, 'returnedAt' => null, 'canceledAt' => null, 'otpExpiresAt' => null,
        ], $overrides);
    }

    private static function quoteJson(): array
    {
        return [
            'id' => self::QUOTE_ID, 'connectionId' => self::CONNECTION_ID, 'sourceAccountId' => self::ACCOUNT_ID,
            'targetAccountId' => self::USD_ACCOUNT_ID, 'sourceCurrency' => 'TRY', 'targetCurrency' => 'USD',
            'sourceAmountMinor' => 3412500, 'targetAmountMinor' => 100000, 'requestedSide' => 'TARGET', 'appliedRate' => '34.1250',
            'taxAmountMinor' => null, 'expiresAt' => '2026-01-01T07:01:20.000Z', 'validForSeconds' => 80,
            'insufficientBalance' => false, 'belowMinimum' => false, 'createdAt' => '2026-01-01T07:00:00.000Z',
        ];
    }

    private static function orderJson(): array
    {
        return [
            'id' => self::ORDER_ID, 'quoteId' => self::QUOTE_ID, 'connectionId' => self::CONNECTION_ID,
            'sourceAccountId' => self::ACCOUNT_ID, 'targetAccountId' => self::USD_ACCOUNT_ID, 'sourceCurrency' => 'TRY',
            'targetCurrency' => 'USD', 'sourceAmountMinor' => 3412500, 'targetAmountMinor' => 100000, 'appliedRate' => '34.1250',
            'description' => null, 'idempotencyKey' => 'fx-2026-0042', 'status' => 'SUBMITTED', 'failureCode' => null,
            'outcomeUnknown' => false, 'initiatedVia' => 'API', 'sourceBalanceAfterMinor' => null, 'targetBalanceAfterMinor' => null,
            'createdAt' => '2026-01-01T07:00:30.000Z', 'submittedAt' => '2026-01-01T07:00:30.000Z', 'completedAt' => null,
            'failedAt' => null, 'fxSessionId' => null,
        ];
    }

    private static function transferRequest(array $overrides = []): array
    {
        return array_merge([
            'accountId' => self::ACCOUNT_ID, 'receiverIban' => 'TR050006200000000000000146', 'receiverName' => 'Örnek Tedarik A.Ş.',
            'amountMinor' => 150075, 'description' => 'Fatura 2026-0042',
        ], $overrides);
    }

    // ── Signing, routes, Idempotency-Key ─────────────────────────────────────

    public function testTheFacadeExposesBothSubClients(): void
    {
        [$client] = $this->make();
        $this->assertInstanceOf(BankTransferClient::class, $client->transfers());
        $this->assertInstanceOf(BankFxClient::class, $client->fx());
    }

    public function testEveryCallIsSignedAndHitsItsRoute(): void
    {
        [$client, $http] = $this->make();
        $transfers = $client->transfers();
        $fx = $client->fx();
        $invocations = [
            'transfers.listPurposes' => ['listTransferPurposes', static fn () => $transfers->listPurposes(self::CONNECTION_ID)],
            'transfers.create' => ['createTransfer', static fn () => $transfers->create(self::transferRequest())],
            'transfers.list' => ['listTransfers', static fn () => $transfers->list()],
            'transfers.iterate' => ['listTransfers', static fn () => iterator_to_array($transfers->iterate())],
            'transfers.get' => ['getTransfer', static fn () => $transfers->get(self::TRANSFER_ID)],
            'fx.listRates' => ['listFxRates', static fn () => $fx->listRates(self::CONNECTION_ID)],
            'fx.createQuote' => ['createFxQuote', static fn () => $fx->createQuote([
                'sourceAccountId' => self::ACCOUNT_ID, 'targetAccountId' => self::USD_ACCOUNT_ID, 'targetAmountMinor' => 100000,
            ])],
            'fx.createOrder' => ['createFxOrder', static fn () => $fx->createOrder(['quoteId' => self::QUOTE_ID])],
            'fx.listOrders' => ['listFxOrders', static fn () => $fx->listOrders()],
            'fx.iterateOrders' => ['listFxOrders', static fn () => iterator_to_array($fx->iterateOrders())],
            'fx.getOrder' => ['getFxOrder', static fn () => $fx->getOrder(self::ORDER_ID)],
        ];

        // The lock covers both public surfaces.
        $surface = [];
        foreach (['transfers' => BankTransferClient::class, 'fx' => BankFxClient::class] as $prefix => $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isConstructor() && !$method->isStatic()) {
                    $surface[] = "{$prefix}.{$method->getName()}";
                }
            }
        }
        sort($surface);
        $expected = array_keys($invocations);
        sort($expected);
        $this->assertSame($expected, $surface);

        foreach ($invocations as $name => [$routeName, $invoke]) {
            $route = BankTransferRoutes::ROUTES[$routeName];
            $http->pushJson($route['method'] === 'POST' ? 201 : 200, str_contains($routeName, 'list') && $routeName !== 'listFxRates' && $routeName !== 'listTransferPurposes'
                ? self::page([])
                : self::envelope(['items' => [], 'transfer' => self::transferJson(), 'order' => self::orderJson()] + self::quoteJson()));
            $invoke();
            $call = $http->lastCall();
            $path = strtok(self::target($call), '?');
            $pattern = '#^' . preg_replace('#:[A-Za-z]+#', '[^/]+', $route['path']) . '$#';
            $this->assertSame($route['method'], $call['method'], $name);
            $this->assertMatchesRegularExpression($pattern, (string) $path, $name);
            $this->assertSame('OK', SignatureGuardStandIn::verdict($call, $this->publicKey, self::BASE_URL), "{$name} must carry a valid API-key signature");
            if ($route['idempotencyKey']) {
                $this->assertMatchesRegularExpression(BankTransferRoutes::IDEMPOTENCY_KEY_PATTERN, $call['headers']['Idempotency-Key'] ?? '', "{$name} sends an Idempotency-Key");
            } else {
                $this->assertArrayNotHasKey('Idempotency-Key', $call['headers'], "{$name} sends no Idempotency-Key");
            }
            if ($route['method'] === 'GET') {
                $this->assertNull($call['body'], "{$name} sends no body");
            }
        }
    }

    public function testCreateTransferSendsTheWhitelistedBodyAndGeneratesAKey(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(201, self::envelope(['transfer' => self::transferJson(), 'stepUp' => null, 'replayed' => false]));
        $http->pushJson(201, self::envelope(['transfer' => self::transferJson(), 'stepUp' => null, 'replayed' => false]));

        $created = $client->transfers()->create(self::transferRequest(['currency' => 'try', 'paymentPurpose' => null]));
        $first = $http->lastCall();
        $this->assertSame([
            'accountId' => self::ACCOUNT_ID, 'receiverIban' => 'TR050006200000000000000146', 'receiverName' => 'Örnek Tedarik A.Ş.',
            'amountMinor' => 150075, 'description' => 'Fatura 2026-0042', 'currency' => 'TRY',
        ], json_decode((string) $first['body'], true));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['headers']['Idempotency-Key']);
        $this->assertSame(BankTransferStatus::SUBMITTED, $created->transfer()->status());
        $this->assertFalse($created->replayed());
        $this->assertNull($created->stepUp());

        $client->transfers()->create(self::transferRequest(), 'payout-2026-0042');
        $this->assertSame('payout-2026-0042', $http->lastCall()['headers']['Idempotency-Key'], 'a caller key is sent as is');
        $this->assertNotSame($first['headers']['Idempotency-Key'], 'payout-2026-0042');
    }

    /** @return array<string,array{0:callable(BankConnectClient):mixed,1:string}> */
    public static function invalidRequests(): array
    {
        return [
            'unknown field' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(['environment' => 'test'])), '/does not accept environment/'],
            'missing receiver' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(['receiverName' => ''])), '/requires receiverName/'],
            'decimal amount' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(['amountMinor' => 1500.75])), '/positive integer/'],
            'zero amount' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(['amountMinor' => 0])), '/positive integer/'],
            'short name' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(['receiverName' => 'A'])), '/2-140/'],
            'long description' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(['description' => str_repeat('a', 141)])), '/140/'],
            'bad purpose' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(['paymentPurpose' => 'kira ödemesi'])), '/paymentPurpose/'],
            'short key' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(), 'short'), '/idempotencyKey/'],
            'key with space' => [static fn (BankConnectClient $c) => $c->transfers()->create(self::transferRequest(), 'payout 2026 0042'), '/idempotencyKey/'],
            'both amounts' => [static fn (BankConnectClient $c) => $c->fx()->createQuote([
                'sourceAccountId' => self::ACCOUNT_ID, 'targetAccountId' => self::USD_ACCOUNT_ID, 'sourceAmountMinor' => 1, 'targetAmountMinor' => 1,
            ]), '/exactly one/'],
            'no amount' => [static fn (BankConnectClient $c) => $c->fx()->createQuote([
                'sourceAccountId' => self::ACCOUNT_ID, 'targetAccountId' => self::USD_ACCOUNT_ID,
            ]), '/exactly one/'],
            'same account' => [static fn (BankConnectClient $c) => $c->fx()->createQuote([
                'sourceAccountId' => self::ACCOUNT_ID, 'targetAccountId' => self::ACCOUNT_ID, 'sourceAmountMinor' => 1,
            ]), '/two different accounts/'],
            'order without quote' => [static fn (BankConnectClient $c) => $c->fx()->createOrder([]), '/requires quoteId/'],
            'fx session on the API' => [static fn (BankConnectClient $c) => $c->fx()->createOrder(['quoteId' => self::QUOTE_ID, 'fxSessionId' => self::QUOTE_ID]), '/does not accept fxSessionId/'],
            'unknown filter' => [static fn (BankConnectClient $c) => $c->transfers()->list(['environment' => 'test']), '/Unknown filter/'],
            'bad status' => [static fn (BankConnectClient $c) => $c->transfers()->list(['status' => 'DONE']), "/'status' must be one of/"],
            'fx status set' => [static fn (BankConnectClient $c) => $c->fx()->listOrders(['status' => BankTransferStatus::RETURNED]), "/'status' must be one of/"],
            'empty id' => [static fn (BankConnectClient $c) => $c->transfers()->get(' '), '/transferId/'],
        ];
    }

    /** @param callable(BankConnectClient):mixed $invoke */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsFailBeforeAnythingIsSent(callable $invoke, string $message): void
    {
        [$client, $http] = $this->make();
        try {
            $invoke($client);
            $this->fail('expected a ConfigurationException');
        } catch (ConfigurationException $error) {
            $this->assertMatchesRegularExpression($message, $error->getMessage());
        }
        $this->assertSame(0, $http->callCount(), 'nothing was sent');
    }

    public function testListFiltersAreSentAsTheGatewayParsesThem(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::transferJson()]));
        $http->pushJson(200, self::page([self::orderJson()]));

        [$row] = $client->transfers()->list([
            'accountId' => self::ACCOUNT_ID,
            'status' => [BankTransferStatus::COMPLETED, BankTransferStatus::RETURNED],
            'createdFrom' => '2026-01-01T00:00:00+03:00',
            'limit' => 50,
        ])->items();
        parse_str((string) parse_url($http->lastCall()['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['limit' => '50', 'accountId' => self::ACCOUNT_ID, 'status' => 'COMPLETED,RETURNED', 'createdFrom' => '2026-01-01T00:00:00+03:00'], $query);
        $this->assertSame('OK', SignatureGuardStandIn::verdict($http->lastCall(), $this->publicKey, self::BASE_URL));
        $this->assertInstanceOf(BankTransfer::class, $row);

        [$order] = $client->fx()->listOrders(['status' => BankFxOrderStatus::FAILED])->items();
        parse_str((string) parse_url($http->lastCall()['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['status' => 'FAILED'], $query);
        $this->assertInstanceOf(BankFxOrder::class, $order);
    }

    public function testTransferQuoteAndOrderRead(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::transferJson(['status' => 'FAILED', 'failureCode' => 'bank_connect.transfer.insufficient_balance', 'rail' => 'FAST'])));
        $http->pushJson(201, self::envelope(self::quoteJson()));
        $http->pushJson(201, self::envelope(['order' => self::orderJson(), 'replayed' => true]));
        $http->pushJson(200, self::envelope(['items' => [['currency' => 'USD', 'pairCurrency' => 'TRY', 'buyRate' => '34.0100', 'sellRate' => '34.2400', 'spread' => null, 'updatedAt' => null]], 'refreshSeconds' => 30, 'fetchedAt' => '2026-01-01T07:00:00.000Z']));
        $http->pushJson(200, self::envelope(['items' => [['code' => 'RENT', 'name' => 'Kira', 'order' => 1]]]));

        $transfer = $client->transfers()->get(self::TRANSFER_ID);
        $this->assertSame(BankTransferStatus::FAILED, $transfer->status());
        $this->assertSame(BankTransferRail::FAST, $transfer->rail());
        $this->assertSame('bank_connect.transfer.insufficient_balance', $transfer->failureCode());
        $this->assertTrue($transfer->isSettled());
        $this->assertNull($transfer->completedAt(), 'a null field reads as null');
        $this->assertSame(BankTransferInitiatedVia::API, $transfer->initiatedVia());
        $this->assertFalse($transfer->outcomeUnknown());

        $quote = $client->fx()->createQuote(['sourceAccountId' => self::ACCOUNT_ID, 'targetAccountId' => self::USD_ACCOUNT_ID, 'targetAmountMinor' => 100000]);
        $this->assertSame(['sourceAccountId' => self::ACCOUNT_ID, 'targetAccountId' => self::USD_ACCOUNT_ID, 'targetAmountMinor' => 100000], json_decode((string) $http->lastCall()['body'], true));
        $this->assertSame(BankFxRequestedSide::TARGET, $quote->requestedSide());
        $this->assertSame('34.1250', $quote->appliedRate(), 'rates stay decimal strings');
        $this->assertSame(80, $quote->validForSeconds());
        $this->assertNull($quote->taxAmountMinor());

        $placed = $client->fx()->createOrder(['quoteId' => $quote->id()], 'fx-2026-0042');
        $this->assertTrue($placed->replayed());
        $this->assertSame(BankFxOrderStatus::SUBMITTED, $placed->order()->status());

        $rates = $client->fx()->listRates(self::CONNECTION_ID);
        $this->assertSame(30, $rates->refreshSeconds());
        $this->assertInstanceOf(BankFxRate::class, $rates->items()[0]);
        $this->assertSame('34.2400', $rates->items()[0]->sellRate());

        $purposes = $client->transfers()->listPurposes(self::CONNECTION_ID);
        $this->assertSame('connectionId=' . self::CONNECTION_ID, parse_url($http->lastCall()['url'], PHP_URL_QUERY));
        $this->assertInstanceOf(BankTransferPurpose::class, $purposes[0]);
        $this->assertSame('RENT', $purposes[0]->code());
    }

    public function testIpAllowlistRefusalKeepsItsCode(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(403, ['code' => 'bank_connect.auth.ip_allowlist_required', 'message' => 'Money movement endpoints require an IP allowlist on the API key.']);
        try {
            $client->transfers()->create(self::transferRequest());
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame(403, $error->getStatusCode());
            $this->assertSame('bank_connect.auth.ip_allowlist_required', $error->getErrorCode());
        }
    }

    // ── Field lock ────────────────────────────────────────────────────────────

    /** @return array<string,array{0:class-string,1:string[]}> */
    public static function responseFields(): array
    {
        return [
            'transfer' => [BankTransfer::class, array_keys(self::transferJson())],
            'quote' => [BankFxQuote::class, array_keys(self::quoteJson())],
            'order' => [BankFxOrder::class, array_keys(self::orderJson())],
            'rate' => [BankFxRate::class, ['currency', 'pairCurrency', 'buyRate', 'sellRate', 'spread', 'updatedAt']],
            'purpose' => [BankTransferPurpose::class, ['code', 'name', 'order']],
            // BankTransferWebhookPayload (transfer-events.ts, webhook-service allowlist).
            'webhook' => [BankTransferEvent::class, [
                'transferId', 'connectionId', 'accountId', 'bankEftCode', 'bankName', 'status', 'rail', 'amountMinor', 'currency',
                'receiverIbanMasked', 'receiverNameMasked', 'paymentPurpose', 'description', 'failureCode', 'idempotencyKey',
                'createdAt', 'completedAt', 'failedAt', 'returnedAt',
            ]],
        ];
    }

    /**
     * @param class-string $class
     * @param string[] $fields
     */
    #[DataProvider('responseFields')]
    public function testOneAccessorPerGatewayField(string $class, array $fields): void
    {
        $derived = ['get', 'raw', 'isSettled', 'type', 'environment', 'fromEvent'];
        $accessors = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || in_array($method->getName(), $derived, true)) {
                continue;
            }
            $accessors[] = $method->getName();
        }
        // Taban sınıftan gelen (türetilmiş) erişimciler iki tarafta da karşılaştırma dışı.
        $fields = array_values(array_diff($fields, $derived));
        sort($accessors);
        sort($fields);
        $this->assertSame($fields, $accessors, "{$class} must expose exactly the gateway's fields");
        $this->assertTrue(is_subclass_of($class, BankConnectResource::class));
    }

    public function testValueSetsMatchTheGateway(): void
    {
        // transfer.types.ts (TRANSFER_STATUSES, TRANSFER_RAILS, TRANSFER_INITIATED_VIA, FX_ORDER_STATUSES,
        // FX_REQUESTED_SIDES); BankTransferWebhookEventType (@app/proto).
        $this->assertSame(['PENDING_OTP', 'SUBMITTED', 'PROCESSING', 'COMPLETED', 'FAILED', 'RETURNED', 'CANCELED'], BankTransferStatus::ALL);
        $this->assertSame(['INTERNAL', 'INTRABANK', 'FAST', 'EFT', 'UNKNOWN'], BankTransferRail::ALL);
        $this->assertSame(['PANEL', 'API'], BankTransferInitiatedVia::ALL);
        $this->assertSame(['SUBMITTED', 'COMPLETED', 'FAILED'], BankFxOrderStatus::ALL);
        $this->assertSame(['SOURCE', 'TARGET'], BankFxRequestedSide::ALL);
        $this->assertSame(['bank_transfer.created', 'bank_transfer.completed', 'bank_transfer.failed', 'bank_transfer.returned'], BankTransferEvents::ALL);
        $this->assertSame('bank_transfer.*', BankTransferEvents::ALL_EVENTS);
    }

    /** The route table's single source is the Node SDK's BANK_TRANSFER_ROUTES. */
    public function testTheRouteTableMatchesTheNodeSdk(): void
    {
        $source = dirname(__DIR__, 3) . '/node/src/bank-connect/bank-connect-values.js';
        if (!is_dir(dirname(__DIR__, 3) . '/node')) {
            $this->markTestSkipped('Parity with the Node SDK is checked in the Lunixi monorepo only.');
        }
        $this->assertFileExists($source);
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $json = shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg(
            'process.stdout.write(JSON.stringify(require(process.argv[1]).BANK_TRANSFER_ROUTES))'
        ) . ' ' . escapeshellarg($source));
        $this->assertSame(json_decode((string) $json, true), BankTransferRoutes::ROUTES);
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    public function testTransferWebhookVerifiesAndReads(): void
    {
        $secret = 'whsec_test_bank_transfer';
        $payload = [
            'transferId' => self::TRANSFER_ID, 'connectionId' => self::CONNECTION_ID, 'accountId' => self::ACCOUNT_ID,
            'bankEftCode' => '0000', 'bankName' => 'ÖRNEK BANKA', 'status' => 'COMPLETED', 'rail' => 'FAST', 'amountMinor' => 150075,
            'currency' => 'TRY', 'receiverIbanMasked' => 'TR00 **** **** **** **** **01 46', 'receiverNameMasked' => 'Ö**** T*******',
            'paymentPurpose' => null, 'description' => null, 'failureCode' => null, 'idempotencyKey' => 'payout-2026-0042',
            'createdAt' => '2026-01-01T07:00:00.000Z', 'completedAt' => '2026-01-01T07:00:20.000Z', 'failedAt' => null, 'returnedAt' => null,
        ];
        $eventId = '00000000-0000-4000-8000-00000000e101';
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $raw = (string) json_encode([
            'id' => $eventId, 'type' => BankTransferEvents::COMPLETED, 'sourceService' => 'bank-connect-service',
            'organizationId' => 'org_1', 'productCode' => 'BANK_CONNECT', 'environment' => 'TEST', 'eventVersion' => 1,
            'schemaVersion' => 'v1', 'createdAt' => $timestamp, 'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'x-lunixi-event-id' => $eventId,
            'x-lunixi-event-type' => BankTransferEvents::COMPLETED,
            'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $raw, $secret),
            'x-lunixi-signature-timestamp' => $timestamp,
        ];

        $event = (new WebhookVerifier())->verify($raw, $headers, $secret);
        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertTrue($event->matches(BankTransferEvents::ALL_EVENTS));
        $transfer = BankTransferEvent::fromEvent($event);
        $this->assertSame(BankTransferEvents::COMPLETED, $transfer->type());
        $this->assertSame('TEST', $transfer->environment());
        $this->assertSame(150075, $transfer->amountMinor());
        $this->assertSame('payout-2026-0042', $transfer->idempotencyKey());
        $this->assertNull($transfer->failureCode());
    }

    public function testFromEventRefusesAnotherEventType(): void
    {
        $this->expectException(ConfigurationException::class);
        BankTransferEvent::fromEvent(new WebhookEvent('evt_1', 'bank_account.transaction.received', '2026-01-01T00:00:00Z', []));
    }
}
