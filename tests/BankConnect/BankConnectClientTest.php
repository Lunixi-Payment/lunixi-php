<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\BankConnect;

use DateTimeImmutable;
use DateTimeZone;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\BankConnect\BankAccountStatus;
use Lunixi\Sdk\BankConnect\BankAccountTransactionReceived;
use Lunixi\Sdk\BankConnect\BankAvailability;
use Lunixi\Sdk\BankConnect\BankCapability;
use Lunixi\Sdk\BankConnect\BankConnectAccount;
use Lunixi\Sdk\BankConnect\BankConnectBalance;
use Lunixi\Sdk\BankConnect\BankConnectBank;
use Lunixi\Sdk\BankConnect\BankConnectBankRef;
use Lunixi\Sdk\BankConnect\BankConnectClient;
use Lunixi\Sdk\BankConnect\BankConnectConnection;
use Lunixi\Sdk\BankConnect\BankConnectEvents;
use Lunixi\Sdk\BankConnect\BankConnectFreshness;
use Lunixi\Sdk\BankConnect\BankConnectionStatus;
use Lunixi\Sdk\BankConnect\BankConnectResource;
use Lunixi\Sdk\BankConnect\BankConnectSyncRequest;
use Lunixi\Sdk\BankConnect\BankConnectTransaction;
use Lunixi\Sdk\BankConnect\BankSyncStatus;
use Lunixi\Sdk\BankConnect\BankTransactionDirection;
use Lunixi\Sdk\BankConnect\BankTransactionType;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\SignatureGuardStandIn;
use Lunixi\Sdk\Webhook\WebhookEvent;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract source: nest-payment-gateway apps/api-gateway/src/bank-connect/api/
 * (controller, service), dtos/bank-connect-api.dto.ts and
 * mappers/bank-connect-api.mapper.ts; webhook payload
 * apps/bank-connect-service/src/sync/bank-account-webhook.ts.
 */
final class BankConnectClientTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';
    private const BANK_ID = '0b9d2f4e-1c3a-4e5f-9a7b-2c4d6e8f0a1b';
    private const CONNECTION_ID = '1c0e3a5f-2d4b-4f6a-8b8c-3d5e7f9a1b2c';
    private const ACCOUNT_ID = '2d1f4b6a-3e5c-4a7b-9c9d-4e6f8a0b2c3d';
    private const OTHER_ACCOUNT_ID = '3e2a5c7b-4f6d-4b8c-8dae-5f7a9b1c3d4e';
    private const TRANSACTION_ID = '4f3b6d8c-5a7e-4c9d-9ebf-6a8b0c2d4e5f';

    /** Raw 32-byte Ed25519 public key of the client under test. */
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
        return self::envelope(['items' => $items], ['pageInfo' => ['hasMore' => $hasMore, 'nextCursor' => $cursor, 'totalCount' => null]]);
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

    // ── Fixtures: exactly the fields bank-connect-api.mapper.ts emits ─────────

    private static function bankRef(): array
    {
        return ['bankId' => self::BANK_ID, 'eftCode' => '0000', 'name' => 'ÖRNEK BANKA'];
    }

    private static function bankJson(): array
    {
        return [
            'bankId' => self::BANK_ID, 'eftCode' => '0000', 'name' => 'ÖRNEK BANKA', 'shortName' => 'ÖRNEK', 'bic' => 'ORNKTRIS',
            'country' => 'TR', 'availability' => 'AVAILABLE', 'capabilities' => ['ACCOUNT_LIST', 'BALANCE', 'TRANSACTIONS'],
        ];
    }

    private static function connectionJson(): array
    {
        return [
            'connectionId' => self::CONNECTION_ID, 'bank' => self::bankRef(), 'status' => 'ACTIVE', 'statusReasonCode' => 'activated',
            'createdAt' => '2026-01-01T06:00:00.000Z', 'activatedAt' => '2026-01-01T07:00:00.000Z',
            'lastSuccessfulSyncAt' => '2026-01-01T07:05:00.000Z', 'accountCount' => 2,
            'capabilities' => ['ACCOUNT_LIST', 'BALANCE', 'TRANSACTIONS', 'OUTGOING_TRANSFER'],
        ];
    }

    private static function accountJson(): array
    {
        return [
            'accountId' => self::ACCOUNT_ID, 'connectionId' => self::CONNECTION_ID, 'bank' => self::bankRef(),
            'ibanMasked' => 'TR00 **** **** **** **** **00 00', 'accountNumberMasked' => '****0000', 'currency' => 'TRY',
            'branchCode' => '0001', 'branchName' => 'MERKEZ', 'productCode' => 'CARIHSP', 'status' => 'ACTIVE',
            'discoveredAt' => '2026-01-01T07:00:00.000Z', 'displayName' => 'ANA HESAP', 'accountType' => 'VADESIZ',
            'freshness' => [
                'lastSuccessfulSyncAt' => '2026-01-01T07:05:00.000Z', 'nextSyncAvailableAt' => '2026-01-01T07:10:00.000Z',
                'minSyncIntervalSeconds' => 300,
            ],
        ];
    }

    private static function balanceJson(): array
    {
        return [
            'accountId' => self::ACCOUNT_ID, 'currency' => 'TRY', 'balanceMinor' => 1250050, 'availableMinor' => 1200050,
            'blockedMinor' => 50000, 'asOf' => '2026-01-01T07:05:00.000Z',
            'availableWithoutOverdraftMinor' => 1000050, 'overdraftAvailableMinor' => 200000,
        ];
    }

    private static function transactionJson(): array
    {
        return [
            'transactionId' => self::TRANSACTION_ID, 'accountId' => self::ACCOUNT_ID, 'direction' => 'CREDIT', 'amountMinor' => 150000,
            'currency' => 'TRY', 'bookedAt' => '2026-01-01T07:00:00.000Z', 'valueAt' => '2026-01-01T07:00:00.000Z',
            'canonicalType' => 'TRANSFER_IN', 'description' => 'LNX-ORNEK-0001', 'counterpartyNameMasked' => 'Ö**** A****',
            'counterpartyIbanMasked' => 'TR00 **** **** **** **** **00 00', 'receiptNo' => 'R-0001',
            'receivedAt' => '2026-01-01T07:05:00.000Z', 'counterpartyBankCode' => '0000', 'counterpartyBankName' => 'ÖRNEK BANKA',
        ];
    }

    private static function transactionDetailJson(): array
    {
        return self::transactionJson() + ['balanceAfterMinor' => 1250050, 'documentNo' => 'D-0001', 'bank' => self::bankRef()];
    }

    private static function syncJson(): array
    {
        return [
            'accountId' => self::ACCOUNT_ID, 'syncStatus' => 'SCHEDULED', 'scheduledAt' => '2026-01-01T07:10:00.000Z',
            'lastSuccessfulSyncAt' => '2026-01-01T07:05:00.000Z', 'minSyncIntervalSeconds' => 300,
        ];
    }

    // ── Signing and routes ────────────────────────────────────────────────────

    public function testEveryCallIsSignedAndHitsItsRoute(): void
    {
        [$client, $http] = $this->make();
        $invocations = [
            'listBanks' => ['GET /api/v1/bank-connect/banks', static fn () => $client->listBanks()],
            'iterateBanks' => ['GET /api/v1/bank-connect/banks', static fn () => iterator_to_array($client->iterateBanks())],
            'listConnections' => ['GET /api/v1/bank-connect/connections', static fn () => $client->listConnections()],
            'iterateConnections' => ['GET /api/v1/bank-connect/connections', static fn () => iterator_to_array($client->iterateConnections())],
            'getConnection' => ['GET /api/v1/bank-connect/connections/' . self::CONNECTION_ID, static fn () => $client->getConnection(self::CONNECTION_ID)],
            'listAccounts' => ['GET /api/v1/bank-connect/accounts', static fn () => $client->listAccounts()],
            'iterateAccounts' => ['GET /api/v1/bank-connect/accounts', static fn () => iterator_to_array($client->iterateAccounts())],
            'getAccount' => ['GET /api/v1/bank-connect/accounts/' . self::ACCOUNT_ID, static fn () => $client->getAccount(self::ACCOUNT_ID)],
            'listBalances' => ['GET /api/v1/bank-connect/balances', static fn () => $client->listBalances()],
            'iterateBalances' => ['GET /api/v1/bank-connect/balances', static fn () => iterator_to_array($client->iterateBalances())],
            'listTransactions' => ['GET /api/v1/bank-connect/transactions', static fn () => $client->listTransactions()],
            'iterateTransactions' => ['GET /api/v1/bank-connect/transactions', static fn () => iterator_to_array($client->iterateTransactions())],
            'getTransaction' => ['GET /api/v1/bank-connect/transactions/' . self::TRANSACTION_ID, static fn () => $client->getTransaction(self::TRANSACTION_ID)],
            'requestSync' => ['POST /api/v1/bank-connect/accounts/' . self::ACCOUNT_ID . '/sync', static fn () => $client->requestSync(self::ACCOUNT_ID)],
        ];

        // The lock covers the whole public surface: a method added later without an
        // entry above fails here instead of shipping unsigned.
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new ReflectionClass(BankConnectClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                // transfers() / fx() return the money-movement sub-clients (BankTransferClientTest).
                static fn (ReflectionMethod $m): bool => !$m->isConstructor() && !$m->isStatic()
                    && !in_array($m->getName(), ['transfers', 'fx'], true)
            )
        );
        sort($methods);
        $expected = array_keys($invocations);
        sort($expected);
        $this->assertSame($expected, $methods);

        foreach ($invocations as $name => [$route, $invoke]) {
            $http->pushJson(str_starts_with($route, 'POST') ? 202 : 200, str_contains($name, 'list') || str_contains($name, 'iterate')
                ? self::page([])
                : self::envelope(['accountId' => self::ACCOUNT_ID]));
            $invoke();
            $call = $http->lastCall();
            $this->assertSame($route, $call['method'] . ' ' . strtok(self::target($call), '?'), $name);
            $this->assertSame('OK', $this->verdict($call), "{$name} must carry a valid API-key signature");
            $this->assertNull($call['body'], "{$name} sends no body");
            $this->assertArrayNotHasKey('Idempotency-Key', $call['headers'], "{$name} needs no Idempotency-Key");
        }
    }

    public function testTheSignatureStandInRejectsAnUnsignedOrRetargetedCall(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]));
        $client->listAccounts(['limit' => 5]);
        $call = $http->lastCall();

        $unsigned = $call;
        unset($unsigned['headers']['X-Signature']);
        $this->assertSame('AUTH_HEADERS_MISSING', $this->verdict($unsigned));

        $retargeted = $call;
        $retargeted['url'] = self::BASE_URL . '/api/v1/bank-connect/accounts?limit=6';
        $this->assertSame('INVALID_SIGNATURE', $this->verdict($retargeted));
    }

    public function testFacadeExposesTheClient(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $lunixi = LunixiClient::create(['baseUrl' => self::BASE_URL, 'keyId' => 'kid', 'privateKey' => $keys['privateKey']], new FakeHttpClient());
        $this->assertInstanceOf(BankConnectClient::class, $lunixi->bankConnect());
    }

    // ── Filters → query ───────────────────────────────────────────────────────

    public function testTransactionFiltersAreSentAsTheGatewayParsesThem(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]));

        $client->listTransactions([
            'accountId' => [self::ACCOUNT_ID, self::OTHER_ACCOUNT_ID],
            'bookedFrom' => new DateTimeImmutable('2026-01-01T00:00:00+03:00'),
            'bookedTo' => '2026-04-02T21:00:00Z',
            'direction' => BankTransactionDirection::CREDIT,
            'minAmountMinor' => 100,
            'maxAmountMinor' => 200,
            'canonicalType' => [BankTransactionType::TRANSFER_IN, BankTransactionType::FEE],
            'limit' => 50,
            'cursor' => 'opaque-cursor',
        ]);

        $call = $http->lastCall();
        $this->assertSame('OK', $this->verdict($call));
        $this->assertSame([
            'limit' => '50',
            'cursor' => 'opaque-cursor',
            'accountId' => self::ACCOUNT_ID . ',' . self::OTHER_ACCOUNT_ID,
            'bookedFrom' => '2025-12-31T21:00:00.000Z',
            'bookedTo' => '2026-04-02T21:00:00Z',
            'direction' => 'CREDIT',
            'minAmountMinor' => '100',
            'maxAmountMinor' => '200',
            'canonicalType' => 'TRANSFER_IN,FEE',
        ], self::queryOf($call));
        $this->assertStringContainsString('canonicalType=TRANSFER_IN%2CFEE', $call['url'], 'the comma list is percent-encoded once and signed as sent');
    }

    public function testAnOffsetIsPercentEncodedAndSigned(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]));
        $client->listTransactions(['bookedFrom' => '2026-03-01T00:00:00+03:00']);
        $call = $http->lastCall();
        $this->assertStringContainsString('bookedFrom=2026-03-01T00%3A00%3A00%2B03%3A00', $call['url']);
        $this->assertSame('OK', $this->verdict($call));
    }

    public function testListFiltersOfTheOtherRoutes(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]))->pushJson(200, self::page([]))->pushJson(200, self::page([]));

        $client->listConnections(['status' => [BankConnectionStatus::ACTIVE, BankConnectionStatus::DEGRADED], 'bankId' => self::BANK_ID]);
        $this->assertSame(['status' => 'ACTIVE,DEGRADED', 'bankId' => self::BANK_ID], self::queryOf($http->lastCall()));

        $client->listAccounts(['connectionId' => self::CONNECTION_ID, 'currency' => 'try', 'status' => BankAccountStatus::ACTIVE]);
        $this->assertSame(['connectionId' => self::CONNECTION_ID, 'currency' => 'TRY', 'status' => 'ACTIVE'], self::queryOf($http->lastCall()));

        $client->listBalances(['accountId' => self::ACCOUNT_ID]);
        $this->assertSame(['accountId' => self::ACCOUNT_ID], self::queryOf($http->lastCall()));
    }

    public function testEmptyFiltersSendNoQuery(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]));
        $client->listTransactions(['accountId' => [], 'direction' => null, 'cursor' => '']);
        $this->assertSame('/api/v1/bank-connect/transactions', self::target($http->lastCall()));
    }

    public function testExactlyNinetyTwoDaysIsAccepted(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]));
        $client->listTransactions(['bookedFrom' => '2026-01-01T00:00:00Z', 'bookedTo' => '2026-04-03T00:00:00Z']);
        $this->assertSame(1, $http->callCount());
    }

    /** @return array<string,array{0:string,1:array<string,mixed>,2:string}> */
    public static function invalidFilters(): array
    {
        return [
            'unknown filter (the gateway answers 400)' => ['listTransactions', ['descriptionContains' => 'LNX'], 'Unknown bank-connect filter'],
            'tenant field' => ['listAccounts', ['merchantId' => 'org_1'], 'Unknown bank-connect filter'],
            'environment is the key\'s' => ['listAccounts', ['environment' => 'live'], 'Unknown bank-connect filter'],
            'includeTotal is not offered' => ['listBanks', ['includeTotal' => true], 'Unknown bank-connect filter'],
            'limit 0' => ['listBanks', ['limit' => 0], "'limit'"],
            'limit 101' => ['listAccounts', ['limit' => 101], "'limit'"],
            'limit as string' => ['listAccounts', ['limit' => '20'], "'limit'"],
            'connection status' => ['listConnections', ['status' => 'OPEN'], "'status'"],
            'account status takes one value' => ['listAccounts', ['status' => ['ACTIVE']], "'status'"],
            'direction' => ['listTransactions', ['direction' => 'IN'], "'direction'"],
            'canonical type' => ['listTransactions', ['canonicalType' => ['TRANSFER_IN', 'NOT_A_TYPE']], "'canonicalType'"],
            'currency' => ['listBalances', ['currency' => 'TL'], "'currency'"],
            'negative amount' => ['listTransactions', ['minAmountMinor' => -1], "'minAmountMinor'"],
            'float amount' => ['listTransactions', ['maxAmountMinor' => 12.5], "'maxAmountMinor'"],
            'min above max' => ['listTransactions', ['minAmountMinor' => 500, 'maxAmountMinor' => 100], "'minAmountMinor' must not be greater"],
            'bookedTo alone' => ['listTransactions', ['bookedTo' => '2026-04-03T00:00:00Z'], "'bookedTo' needs 'bookedFrom'"],
            'reversed window' => ['listTransactions', ['bookedFrom' => '2026-04-03T00:00:00Z', 'bookedTo' => '2026-04-01T00:00:00Z'], 'must not be after'],
            '92 days + 1 ms' => ['listTransactions', ['bookedFrom' => '2026-01-01T00:00:00Z', 'bookedTo' => '2026-04-03T00:00:00.001Z'], 'at most 92 days'],
            'more than 100 account ids' => ['listBalances', ['accountId' => array_fill(0, 101, self::ACCOUNT_ID)], 'at most 100'],
            'account id with a comma' => ['listTransactions', ['accountId' => [self::ACCOUNT_ID . ',x']], "'accountId'"],
        ];
    }

    /** @param array<string,mixed> $filters */
    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersFailBeforeAnythingIsSent(string $method, array $filters, string $message): void
    {
        [$client, $http] = $this->make();
        try {
            $client->{$method}($filters);
            $this->fail('expected a ConfigurationException');
        } catch (ConfigurationException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testEmptyIdsFailBeforeAnythingIsSent(): void
    {
        [$client, $http] = $this->make();
        foreach (['getConnection', 'getAccount', 'getTransaction', 'requestSync'] as $method) {
            try {
                $client->{$method}('  ');
                $this->fail("{$method}: expected a ConfigurationException");
            } catch (ConfigurationException $error) {
                $this->assertStringContainsString('is required', $error->getMessage());
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testIdsAreEscapedIntoThePath(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(404, ['code' => 'bank_connect.account.not_found', 'message' => 'Bank account not found.']);
        try {
            $client->getAccount('a/b?c');
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame(404, $error->getStatusCode());
            $this->assertSame('bank_connect.account.not_found', $error->getErrorCode());
        }
        $this->assertSame('/api/v1/bank-connect/accounts/a%2Fb%3Fc', self::target($http->lastCall()));
    }

    // ── Responses ─────────────────────────────────────────────────────────────

    public function testPagesReadTopLevelPageInfoAndIterateFollowsTheCursor(): void
    {
        [$client, $http] = $this->make();
        $second = self::transactionJson();
        $second['transactionId'] = '5a4c7e9d-6b8f-4dae-8fca-7b9c1d3e5f6a';
        $http->pushJson(200, self::page([self::transactionJson()], true, 'cursor-2'));
        $http->pushJson(200, self::page([$second]));

        $ids = [];
        foreach ($client->iterateTransactions(['limit' => 1, 'direction' => 'CREDIT']) as $transaction) {
            $this->assertInstanceOf(BankConnectTransaction::class, $transaction);
            $ids[] = $transaction->transactionId();
        }

        $this->assertSame([self::TRANSACTION_ID, $second['transactionId']], $ids);
        $this->assertSame(2, $http->callCount());
        $this->assertArrayNotHasKey('cursor', self::queryOf($http->calls[0]));
        $this->assertSame(['limit' => '1', 'cursor' => 'cursor-2', 'direction' => 'CREDIT'], self::queryOf($http->calls[1]));
    }

    public function testThePageIsLastWithoutACursor(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::bankJson()], true, null));
        $page = $client->listBanks();
        $this->assertSame(1, $page->count());
        $this->assertFalse($page->hasMore(), 'hasMore without a cursor cannot be followed');
        $this->assertNull($page->nextCursor());
    }

    public function testBankRow(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::bankJson(), ['bankId' => self::BANK_ID, 'eftCode' => '0001', 'name' => 'YAKINDA BANK', 'availability' => 'COMING_SOON', 'capabilities' => []]]));
        [$bank, $soon] = $client->listBanks()->items();

        $this->assertSame('0000', $bank->eftCode());
        $this->assertTrue($bank->isAvailable());
        $this->assertTrue($bank->supports(BankCapability::TRANSACTIONS));
        $this->assertSame('ORNKTRIS', $bank->bic());
        $this->assertSame(BankAvailability::COMING_SOON, $soon->availability());
        $this->assertFalse($soon->isAvailable());
        $this->assertSame([], $soon->capabilities());
        $this->assertNull($soon->shortName(), 'a field the gateway leaves out reads as null');
    }

    public function testConnectionAndAccount(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::connectionJson()));
        $http->pushJson(200, self::envelope(self::accountJson()));

        $connection = $client->getConnection(self::CONNECTION_ID);
        $this->assertSame(BankConnectionStatus::ACTIVE, $connection->status());
        $this->assertSame('activated', $connection->statusReasonCode());
        $this->assertSame('ÖRNEK BANKA', $connection->bank()->name());
        $this->assertSame(2, $connection->accountCount());
        $this->assertTrue($connection->supports(BankCapability::OUTGOING_TRANSFER));
        $this->assertFalse($connection->supports(BankCapability::FX_EXCHANGE));

        $account = $client->getAccount(self::ACCOUNT_ID);
        $this->assertSame('TR00 **** **** **** **** **00 00', $account->ibanMasked());
        $this->assertSame(300, $account->freshness()->minSyncIntervalSeconds());
        $this->assertSame('2026-01-01T07:10:00.000Z', $account->freshness()->nextSyncAvailableAt());
        $this->assertSame(self::CONNECTION_ID, $account->connectionId());
        $this->assertSame('CARIHSP', $account->productCode());
        $this->assertSame('ANA HESAP', $account->displayName());
        $this->assertSame('VADESIZ', $account->accountType());
    }

    public function testBalanceAmountsAreIntegersAndUnreportedOnesAreNull(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::balanceJson(), ['accountId' => self::OTHER_ACCOUNT_ID, 'currency' => 'TRY', 'balanceMinor' => 0]]));
        [$full, $bare] = $client->listBalances()->items();

        $this->assertSame(1250050, $full->balanceMinor());
        $this->assertSame(1200050, $full->availableMinor());
        $this->assertSame(50000, $full->blockedMinor());
        $this->assertSame(1000050, $full->availableWithoutOverdraftMinor());
        $this->assertSame(200000, $full->overdraftAvailableMinor());
        $this->assertNull($bare->overdraftAvailableMinor());
        $this->assertSame(0, $bare->balanceMinor());
        $this->assertNull($bare->availableMinor(), 'not reported is null, not 0');
        $this->assertNull($bare->asOf());
    }

    public function testTransactionRowAndDetail(): void
    {
        [$client, $http] = $this->make();
        $row = self::transactionJson();
        unset($row['valueAt'], $row['receiptNo']);
        $http->pushJson(200, self::page([$row]));
        $http->pushJson(200, self::envelope(self::transactionDetailJson()));

        [$listed] = $client->listTransactions()->items();
        $this->assertTrue($listed->isCredit());
        $this->assertSame(150000, $listed->amountMinor());
        $this->assertNull($listed->valueAt());
        $this->assertNull($listed->receiptNo());
        $this->assertNull($listed->bank(), 'a list row has no bank');
        $this->assertNull($listed->balanceAfterMinor());
        $this->assertFalse($listed->isReversal(), 'the gateway leaves isReversal out unless true');
        $this->assertNull($listed->reversalOfTransactionId());
        $this->assertSame('0000', $listed->counterpartyBankCode());

        $detail = $client->getTransaction(self::TRANSACTION_ID);
        $this->assertSame(1250050, $detail->balanceAfterMinor());
        $this->assertSame('D-0001', $detail->documentNo());
        $this->assertInstanceOf(BankConnectBankRef::class, $detail->bank());
        $this->assertSame('LNX-ORNEK-0001', $detail->description());
        $this->assertSame(BankTransactionType::TRANSFER_IN, $detail->canonicalType());
    }

    public function testRequestSyncAnswers202(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(202, self::envelope(self::syncJson()));
        $sync = $client->requestSync(self::ACCOUNT_ID);

        $this->assertSame(BankSyncStatus::SCHEDULED, $sync->syncStatus());
        $this->assertFalse($sync->isQueued());
        $this->assertSame('2026-01-01T07:10:00.000Z', $sync->scheduledAt());
        $this->assertSame(300, $sync->minSyncIntervalSeconds());
    }

    public function testRequestSyncRateLimitIsReadable(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(429, [
            'code' => 'RATE_LIMITED',
            'message' => 'Too many sync requests for this API key. Retry after the indicated time.',
            'details' => ['retryAfterSeconds' => 42],
        ], ['Retry-After' => '42']);

        try {
            $client->requestSync(self::ACCOUNT_ID);
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame(429, $error->getStatusCode());
            $this->assertSame(BankConnectClient::RATE_LIMITED, $error->getErrorCode());
            $this->assertSame(42, BankConnectClient::retryAfterSeconds($error));
        }
        $this->assertSame(1, $http->callCount(), 'a 429 is not retried');
        $this->assertNull(BankConnectClient::retryAfterSeconds(new ApiException('x', 409, 'bank_connect.connection.not_active')));
    }

    public function testRequestSyncOnAnInactiveConnection(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(409, ['code' => 'bank_connect.connection.not_active', 'message' => 'Bank connection is not active.', 'details' => ['status' => 'SUSPENDED']]);
        try {
            $client->requestSync(self::ACCOUNT_ID);
            $this->fail('expected an ApiException');
        } catch (ApiException $error) {
            $this->assertSame('bank_connect.connection.not_active', $error->getErrorCode());
        }
    }

    // ── Field lock: accessors = gateway fields ────────────────────────────────

    /** @return array<string,array{0:class-string,1:string[]}> */
    public static function responseFields(): array
    {
        // bank-connect-api.mapper.ts: ApiBankJson, ApiBankRef, ApiConnectionJson, ApiFreshnessJson,
        // ApiAccountJson, ApiBalanceJson, ApiTransactionDetailJson, ApiSyncRequestJson; webhook
        // BankAccountWebhookPayload (bank-account-webhook.ts).
        return [
            'bank' => [BankConnectBank::class, ['bankId', 'eftCode', 'name', 'shortName', 'bic', 'country', 'availability', 'capabilities']],
            'bank ref' => [BankConnectBankRef::class, ['bankId', 'eftCode', 'name']],
            'connection' => [BankConnectConnection::class, ['connectionId', 'bank', 'status', 'statusReasonCode', 'createdAt', 'activatedAt', 'lastSuccessfulSyncAt', 'accountCount', 'capabilities']],
            'freshness' => [BankConnectFreshness::class, ['lastSuccessfulSyncAt', 'nextSyncAvailableAt', 'minSyncIntervalSeconds']],
            'account' => [BankConnectAccount::class, [
                'accountId', 'connectionId', 'bank', 'ibanMasked', 'accountNumberMasked', 'currency', 'branchCode', 'branchName', 'productCode',
                'status', 'discoveredAt', 'freshness', 'displayName', 'accountType',
            ]],
            'balance' => [BankConnectBalance::class, [
                'accountId', 'currency', 'balanceMinor', 'availableMinor', 'blockedMinor', 'asOf', 'availableWithoutOverdraftMinor', 'overdraftAvailableMinor',
            ]],
            'transaction' => [BankConnectTransaction::class, [
                'transactionId', 'accountId', 'direction', 'amountMinor', 'currency', 'bookedAt', 'valueAt', 'canonicalType', 'description',
                'counterpartyNameMasked', 'counterpartyIbanMasked', 'receiptNo', 'receivedAt', 'balanceAfterMinor', 'documentNo', 'bank',
                'isReversal', 'reversalOfTransactionId', 'counterpartyBankCode', 'counterpartyBankName',
            ]],
            'sync' => [BankConnectSyncRequest::class, ['accountId', 'syncStatus', 'scheduledAt', 'lastSuccessfulSyncAt', 'minSyncIntervalSeconds']],
            'webhook' => [BankAccountTransactionReceived::class, [
                'transactionId', 'accountId', 'connectionId', 'bankEftCode', 'bankName', 'direction', 'amountMinor', 'currency', 'bookedAt',
                'valueAt', 'receivedAt', 'canonicalType', 'description', 'counterpartyNameMasked', 'counterpartyIbanMasked', 'receiptNo',
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
        // Convenience readers derived from a field; they add no field of their own.
        $derived = ['get', 'raw', 'isAvailable', 'supports', 'isCredit', 'isDebit', 'isQueued', 'environment', 'fromEvent'];
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
        $this->assertTrue(is_subclass_of($class, BankConnectResource::class));
    }

    public function testEnumSetsMatchTheGateway(): void
    {
        // bank-connect.constants.ts (CONNECTION_STATUSES, ACCOUNT_STATUSES, TRANSACTION_DIRECTIONS,
        // CANONICAL_TRANSACTION_TYPES, API_BANK_CAPABILITIES) and the mapper's availability / sync status.
        $this->assertSame(['PENDING_DOCUMENTS', 'UNDER_REVIEW', 'PENDING_SETUP', 'ACTIVE', 'DEGRADED', 'SUSPENDED', 'DISCONNECTED'], BankConnectionStatus::ALL);
        $this->assertSame(['ACTIVE', 'INACTIVE'], BankAccountStatus::ALL);
        $this->assertSame(['CREDIT', 'DEBIT'], BankTransactionDirection::ALL);
        $this->assertSame([
            'TRANSFER_IN', 'TRANSFER_OUT', 'INTERNAL_IN', 'INTERNAL_OUT', 'POS_SETTLEMENT', 'POS_SETTLEMENT_REFUND', 'FEE', 'TAX',
            'CASH_IN', 'CASH_OUT', 'CARD', 'LOAN', 'FX', 'INTEREST', 'OTHER',
        ], BankTransactionType::ALL);
        $this->assertSame(['ACCOUNT_LIST', 'BALANCE', 'TRANSACTIONS', 'OUTGOING_TRANSFER', 'FX_EXCHANGE'], BankCapability::ALL);
        $this->assertSame(['AVAILABLE', 'COMING_SOON'], BankAvailability::ALL);
        $this->assertSame(['QUEUED', 'SCHEDULED'], BankSyncStatus::ALL);
        $this->assertSame(['bank_account.transaction.received'], BankConnectEvents::ALL);
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    public function testTransactionReceivedWebhookVerifiesAndReads(): void
    {
        $secret = 'whsec_test_bank_connect';
        $eventId = '6b5d8f0e-7c9a-5ebf-9adb-8c0d2e4f6a7b';
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        // The delivery envelope webhook-service builds (buildDeliveryPayload), `data` = the payload.
        $body = (string) json_encode([
            'id' => $eventId,
            'type' => 'bank_account.transaction.received',
            'sourceService' => 'bank-connect-service',
            'organizationId' => 'org_1',
            'productCode' => 'BANK_CONNECT',
            'environment' => 'TEST',
            'eventVersion' => 1,
            'schemaVersion' => 'v1',
            'createdAt' => $timestamp,
            'data' => [
                'transactionId' => self::TRANSACTION_ID, 'accountId' => self::ACCOUNT_ID, 'connectionId' => self::CONNECTION_ID,
                'bankEftCode' => '0000', 'bankName' => 'ÖRNEK BANKA', 'direction' => 'DEBIT', 'amountMinor' => 2500,
                'currency' => 'TRY', 'bookedAt' => '2026-01-01T07:00:00.000Z', 'valueAt' => null,
                'receivedAt' => '2026-01-01T07:05:00.000Z', 'canonicalType' => 'FEE', 'description' => null,
                'counterpartyNameMasked' => null, 'counterpartyIbanMasked' => null, 'receiptNo' => null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'x-lunixi-event-id' => $eventId,
            'x-lunixi-event-type' => 'bank_account.transaction.received',
            'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $body, $secret),
            'x-lunixi-signature-timestamp' => $timestamp,
        ];

        $event = (new WebhookVerifier())->verify($body, $headers, $secret);
        $this->assertSame(BankConnectEvents::TRANSACTION_RECEIVED, $event->type());
        $this->assertTrue($event->matches(BankConnectEvents::ALL_EVENTS));

        $txn = BankAccountTransactionReceived::fromEvent($event);
        $this->assertSame('TEST', $txn->environment());
        $this->assertSame(self::TRANSACTION_ID, $txn->transactionId());
        $this->assertSame(2500, $txn->amountMinor());
        $this->assertFalse($txn->isCredit());
        $this->assertSame('0000', $txn->bankEftCode());
        $this->assertNull($txn->valueAt(), 'a null field reads as null');
        $this->assertNull($txn->description());
    }

    public function testFromEventRefusesAnotherEventType(): void
    {
        $this->expectException(ConfigurationException::class);
        BankAccountTransactionReceived::fromEvent(new WebhookEvent('evt_1', 'payment.captured', '2026-01-01T00:00:00Z', ['intentId' => 'pi_1']));
    }

    public function testDateTimesAreSentInUtcWithMilliseconds(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([]));
        $from = new DateTimeImmutable('2026-03-01 00:00:00.123456', new DateTimeZone('Europe/Istanbul'));
        $client->listTransactions(['bookedFrom' => $from, 'bookedTo' => $from->modify('+1 day')]);
        $query = self::queryOf($http->lastCall());
        $this->assertSame('2026-02-28T21:00:00.123Z', $query['bookedFrom']);
        $this->assertSame('2026-03-01T21:00:00.123Z', $query['bookedTo']);
    }
}
