<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\PhysicalPos;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Exception\PhysicalPosCardDataRejectedException;
use Lunixi\Sdk\Exception\PhysicalPosOutcomeUnknownException;
use Lunixi\Sdk\Exception\PhysicalPosRateLimitException;
use Lunixi\Sdk\PhysicalPos\PhysicalPosManagementClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosWriteResult;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\SignatureGuardStandIn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract source: nest-payment-gateway @ 8d28433e2 (origin/main)
 * apps/api-gateway/src/physical-pos/{stores,terminals,devices,catalog,overview}/** (API controllers, DTOs, mappers,
 * services). Ids and values below are synthetic.
 */
final class PhysicalPosManagementClientTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';
    private const BASE = '/api/v1/physical-pos';
    private const STORE_ID = '11111111-1111-4111-8111-111111111111';
    private const REGISTER_ID = '22222222-2222-4222-8222-222222222222';
    private const TERMINAL_ID = '33333333-3333-4333-8333-333333333333';
    private const ACQUIRER_ID = '44444444-4444-4444-8444-444444444444';
    private const DEVICE_ID = '55555555-5555-4555-8555-555555555555';
    private const KEY = 'pos-mgmt-key-0001';
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $publicKey = '';

    /** @return array{0:PhysicalPosManagementClient,1:FakeHttpClient} */
    private function make(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $this->publicKey = SignatureGuardStandIn::rawPublicKey($keys['publicKey']);

        $config = new Configuration([
            'baseUrl' => self::BASE_URL,
            'keyId' => 'mk_live_sk_kid_1',
            'privateKey' => $keys['privateKey'],
            'maxRetries' => 0,
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $store->set($config->tokenCacheKey(), 'at_test_seeded', 3600);

        return [new PhysicalPosManagementClient(new ApiClient($config, $signer, new TokenManager($config, $signer, $http, $store), $http)), $http];
    }

    /** @param array{method:string,url:string,headers:array<string,string>,body:?string} $call */
    private function verdict(array $call): string
    {
        return SignatureGuardStandIn::verdict($call, $this->publicKey, self::BASE_URL);
    }

    /** @param mixed $data @return array<string,mixed> */
    private static function envelope($data, int $statusCode = 200): array
    {
        return ['status' => 'success', 'code' => 'SUCCESS', 'data' => $data, 'statusCode' => $statusCode, 'requestId' => 'req_1'];
    }

    private static function page(array $items, bool $hasMore = false, ?string $cursor = null, ?int $total = null): array
    {
        $pageInfo = ['hasMore' => $hasMore, 'nextCursor' => $cursor, 'totalCount' => $total];

        return array_merge(self::envelope(['items' => $items, 'pageInfo' => $pageInfo]), ['pageInfo' => $pageInfo]);
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

    private static function storeJson(array $overrides = []): array
    {
        return array_merge([
            'id' => self::STORE_ID, 'code' => 'IST-01', 'name' => 'Kadikoy', 'city' => null, 'district' => null, 'addressLine' => null,
            'timezone' => 'Europe/Istanbul', 'storeStatus' => 'ACTIVE', 'externalRef' => null, 'rowVersion' => 1,
            'createdAt' => '2026-10-30T09:00:00.000Z', 'updatedAt' => '2026-10-30T09:00:00.000Z',
        ], $overrides);
    }

    private static function deviceJson(array $overrides = []): array
    {
        return array_merge([
            'id' => self::DEVICE_ID, 'displayName' => null, 'vendorCode' => 'PAVO', 'modelId' => 'm1', 'modelDisplayName' => 'N86',
            'modelImage' => null, 'vendorFallbackImage' => null, 'deviceType' => 'PHYSICAL', 'formFactor' => 'HANDHELD',
            'serialNumber' => 'PAV00012345', 'environment' => 'LIVE', 'lifecycleStatus' => 'PENDING', 'ownershipStatus' => 'UNVERIFIED',
            'integrationChannel' => 'APP2APP', 'appMode' => null, 'store' => null, 'register' => null, 'logicalTerminal' => null,
            'createdAt' => '2026-10-30T09:00:00.000Z', 'rowVersion' => 1, 'updatedAt' => '2026-10-30T09:00:00.000Z',
        ], $overrides);
    }

    // ── Overview, catalog ───────────────────────────────────────────────────

    public function testOverviewAndCatalog(): void
    {
        [$client, $http] = $this->make();
        $overview = [
            'stores' => ['active' => 1, 'archived' => 0], 'registers' => ['active' => 2, 'archived' => 0],
            'terminals' => ['environment' => 'LIVE', 'open' => 1, 'closed' => 0], 'devices' => null, 'transactions' => null,
            'generatedAt' => '2026-10-30T09:00:00.000Z',
        ];
        $http->pushJson(200, self::envelope(['overview' => $overview]));
        $http->pushJson(200, self::envelope([['code' => 'PAVO', 'displayName' => 'Pavo', 'status' => 'ACTIVE', 'fallbackImage' => null]]));
        $http->pushJson(200, self::envelope([['id' => 'm1', 'vendorCode' => 'PAVO', 'modelCode' => 'N86']]));
        $http->pushJson(200, self::envelope([['acquirerCode' => 'BANK', 'displayName' => 'Bank']]));

        $this->assertSame($overview, $client->getOverview());
        $this->assertSame(self::BASE . '/overview', self::target($http->lastCall()));
        $this->assertSame('PAVO', $client->listVendors()[0]['code']);
        $this->assertSame(self::BASE . '/catalog/vendors', self::target($http->lastCall()));
        $this->assertSame('N86', $client->listDeviceModels(['vendorCode' => 'PAVO', 'deviceType' => 'PHYSICAL'])[0]['modelCode']);
        $this->assertSame(self::BASE . '/catalog/device-models?vendorCode=PAVO&deviceType=PHYSICAL', self::target($http->lastCall()));
        $this->assertSame('BANK', $client->listAcquirers()[0]['acquirerCode']);
        foreach ($http->calls as $call) {
            $this->assertSame('OK', $this->verdict($call));
        }
    }

    // ── Stores, registers ───────────────────────────────────────────────────

    public function testCreateStoreGeneratesAKeyAndReads201(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(201, self::envelope(['store' => self::storeJson(), 'replayed' => false], 201));

        $result = $client->createStore(['code' => 'IST-01', 'name' => 'Kadikoy', 'timezone' => 'Europe/Istanbul']);

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE . '/stores', self::target($call));
        $this->assertSame('OK', $this->verdict($call));
        $this->assertMatchesRegularExpression(self::UUID_V4, $call['headers']['Idempotency-Key']);
        $this->assertSame(['code' => 'IST-01', 'name' => 'Kadikoy', 'timezone' => 'Europe/Istanbul'], self::bodyOf($call));
        $this->assertInstanceOf(PhysicalPosWriteResult::class, $result);
        $this->assertSame(201, $result->statusCode());
        $this->assertFalse($result->replayed());
        $this->assertSame(self::STORE_ID, $result->record()['id']);
        $this->assertSame($call['headers']['Idempotency-Key'], $result->idempotencyKey());
    }

    public function testCreateReplayIs200WithTheCallersKey(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::envelope(['register' => ['id' => self::REGISTER_ID], 'replayed' => true], 200));

        $result = $client->createRegister(self::STORE_ID, ['code' => 'K1', 'name' => 'Kasa 1'], self::KEY);

        $this->assertSame(self::BASE . '/stores/' . self::STORE_ID . '/registers', self::target($http->lastCall()));
        $this->assertSame(self::KEY, $http->lastCall()['headers']['Idempotency-Key']);
        $this->assertSame(200, $result->statusCode());
        $this->assertTrue($result->replayed());
        $this->assertSame(self::REGISTER_ID, $result->record()['id']);
    }

    public function testStoreReadsUpdatesAndStatusChanges(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([array_merge(self::storeJson(), ['registerCount' => 2])], true, 'cur_2', 7));
        $http->pushJson(200, self::envelope(['store' => self::storeJson(), 'registerSummary' => ['activeCount' => 2, 'archivedCount' => 0], 'assignedDeviceCount' => 1]));
        $http->pushJson(200, self::envelope(['store' => self::storeJson(['city' => null, 'rowVersion' => 2])]));
        $http->pushJson(200, self::envelope(['store' => self::storeJson(['storeStatus' => 'ARCHIVED']), 'archivedRegisterCount' => 2]));
        $http->pushJson(200, self::envelope(['store' => self::storeJson()]));

        $page = $client->listStores(['includeTotal' => true, 'status' => 'ACTIVE', 'q' => 'IST']);
        $this->assertSame(['includeTotal' => 'true', 'status' => 'ACTIVE', 'q' => 'IST'], self::queryOf($http->lastCall()));
        $this->assertSame(7, $page->totalCount());
        $this->assertSame(2, $page->items()[0]['registerCount']);

        $this->assertSame(1, $client->getStore(self::STORE_ID)['assignedDeviceCount']);

        $updated = $client->updateStore(self::STORE_ID, ['rowVersion' => 1, 'city' => null, 'name' => 'Kadikoy 2']);
        $this->assertSame('PATCH', $http->lastCall()['method']);
        $this->assertSame(['rowVersion' => 1, 'name' => 'Kadikoy 2', 'city' => null], self::bodyOf($http->lastCall()));
        $this->assertArrayNotHasKey('Idempotency-Key', $http->lastCall()['headers']);
        $this->assertSame(2, $updated['rowVersion']);

        $archived = $client->archiveStore(self::STORE_ID, ['rowVersion' => 2, 'reasonCode' => 'STORE_CLOSED']);
        $this->assertSame(self::BASE . '/stores/' . self::STORE_ID . '/archive', self::target($http->lastCall()));
        $this->assertSame(2, $archived['archivedRegisterCount']);

        $client->restoreStore(self::STORE_ID, ['rowVersion' => 3, 'reasonCode' => 'REOPENED']);
        $this->assertSame(self::BASE . '/stores/' . self::STORE_ID . '/restore', self::target($http->lastCall()));
        foreach ($http->calls as $call) {
            $this->assertSame('OK', $this->verdict($call));
        }
    }

    public function testRegisterRoutes(): void
    {
        [$client, $http] = $this->make();
        $register = ['id' => self::REGISTER_ID, 'registerStatus' => 'ACTIVE'];
        $http->pushJson(200, self::page([$register]));
        $http->pushJson(200, self::envelope(['register' => $register]));
        $http->pushJson(200, self::envelope(['register' => $register]));
        $http->pushJson(200, self::envelope(['register' => $register]));
        $http->pushJson(200, self::page([]));
        $http->pushJson(200, self::page([]));

        $client->listRegisters(self::STORE_ID, ['status' => 'ACTIVE']);
        $this->assertSame(self::BASE . '/stores/' . self::STORE_ID . '/registers?status=ACTIVE', self::target($http->lastCall()));
        $client->updateRegister(self::REGISTER_ID, ['rowVersion' => 1, 'name' => 'Kasa 2']);
        $this->assertSame(self::BASE . '/registers/' . self::REGISTER_ID, self::target($http->lastCall()));
        $client->archiveRegister(self::REGISTER_ID, ['rowVersion' => 2, 'reasonCode' => 'REGISTER_REMOVED']);
        $this->assertSame(self::BASE . '/registers/' . self::REGISTER_ID . '/archive', self::target($http->lastCall()));
        $client->restoreRegister(self::REGISTER_ID, ['rowVersion' => 3, 'reasonCode' => 'REOPENED']);
        $this->assertSame(self::BASE . '/registers/' . self::REGISTER_ID . '/restore', self::target($http->lastCall()));
        $client->listRegisterAudit(self::REGISTER_ID, ['limit' => 5]);
        $this->assertSame(self::BASE . '/registers/' . self::REGISTER_ID . '/audit?limit=5', self::target($http->lastCall()));
        $client->listStoreAudit(self::STORE_ID);
        $this->assertSame(self::BASE . '/stores/' . self::STORE_ID . '/audit', self::target($http->lastCall()));
    }

    // ── Terminals ───────────────────────────────────────────────────────────

    public function testTerminalRoutes(): void
    {
        [$client, $http] = $this->make();
        $terminal = ['id' => self::TERMINAL_ID, 'terminalStatus' => 'ACTIVE', 'rowVersion' => 1];
        $acquirer = ['id' => self::ACQUIRER_ID, 'terminalId' => self::TERMINAL_ID, 'currencies' => ['TRY']];
        $http->pushJson(200, self::page([$terminal]));
        $http->pushJson(201, self::envelope(['terminal' => $terminal, 'replayed' => false], 201));
        $http->pushJson(200, self::envelope(['terminal' => $terminal, 'acquirers' => ['items' => [$acquirer], 'pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => null]]]));
        $http->pushJson(200, self::envelope(['terminal' => $terminal]));
        $http->pushJson(200, self::envelope(['terminal' => $terminal]));
        $http->pushJson(200, self::page([$acquirer]));
        $http->pushJson(201, self::envelope(['acquirer' => $acquirer, 'replayed' => false], 201));
        $http->pushJson(200, self::envelope(['acquirer' => $acquirer]));
        $http->pushJson(200, self::envelope(['terminal' => $terminal, 'link' => ['id' => 'l1', 'deviceId' => self::DEVICE_ID]]));
        $http->pushJson(200, self::envelope(['terminal' => $terminal, 'link' => null]));
        $http->pushJson(200, self::page([]));
        $http->pushJson(200, self::page([]));

        $client->listTerminals(['status' => 'ACTIVE', 'q' => 'T']);
        $this->assertSame(self::BASE . '/terminals?status=ACTIVE&q=T', self::target($http->lastCall()));

        $created = $client->createTerminal(['code' => 'T1', 'displayName' => 'Terminal 1', 'vendorCode' => 'PAVO']);
        $this->assertSame(201, $created->statusCode());
        $this->assertSame(self::TERMINAL_ID, $created->record()['id']);

        $detail = $client->getTerminal(self::TERMINAL_ID);
        $this->assertSame(self::ACQUIRER_ID, $detail['acquirers']['items'][0]['id']);

        $client->updateTerminal(self::TERMINAL_ID, ['rowVersion' => 1, 'displayName' => 'T 1']);
        $this->assertSame('PATCH', $http->lastCall()['method']);
        $client->closeTerminal(self::TERMINAL_ID, ['rowVersion' => 2, 'reasonCode' => 'OTHER']);
        $this->assertSame(self::BASE . '/terminals/' . self::TERMINAL_ID . '/close', self::target($http->lastCall()));

        $client->listTerminalAcquirers(self::TERMINAL_ID, ['includeTotal' => false]);
        $this->assertSame(self::BASE . '/terminals/' . self::TERMINAL_ID . '/acquirers?includeTotal=false', self::target($http->lastCall()));

        $added = $client->addTerminalAcquirer(self::TERMINAL_ID, [
            'vendorAcquirerRef' => 'B1', 'merchantNumber' => 'M1', 'terminalNumber' => 'T1', 'currencies' => ['TRY'],
        ], self::KEY);
        $this->assertSame(self::KEY, $http->lastCall()['headers']['Idempotency-Key']);
        $this->assertSame(['TRY'], $added->record()['currencies']);

        $client->updateTerminalAcquirer(self::ACQUIRER_ID, ['rowVersion' => 1, 'label' => null, 'status' => 'DISABLED']);
        $this->assertSame(self::BASE . '/terminal-acquirers/' . self::ACQUIRER_ID, self::target($http->lastCall()));
        $this->assertSame(['rowVersion' => 1, 'label' => null, 'status' => 'DISABLED'], self::bodyOf($http->lastCall()));

        $linked = $client->changeTerminalDevice(self::TERMINAL_ID, ['rowVersion' => 3, 'deviceId' => self::DEVICE_ID, 'reasonCode' => 'DEVICE_REPLACEMENT']);
        $this->assertSame(self::BASE . '/terminals/' . self::TERMINAL_ID . '/device-link', self::target($http->lastCall()));
        $this->assertArrayNotHasKey('Idempotency-Key', $http->lastCall()['headers']);
        $this->assertSame(self::DEVICE_ID, $linked['link']['deviceId']);

        $closed = $client->closeTerminalDeviceLink(self::TERMINAL_ID, ['rowVersion' => 4, 'reasonCode' => 'DEVICE_FAULT']);
        $this->assertSame(self::BASE . '/terminals/' . self::TERMINAL_ID . '/device-link/close', self::target($http->lastCall()));
        $this->assertMatchesRegularExpression(self::UUID_V4, $http->lastCall()['headers']['Idempotency-Key']);
        $this->assertNull($closed->data()['link']);
        $this->assertSame(200, $closed->statusCode());

        $client->listTerminalLinks(self::TERMINAL_ID);
        $this->assertSame(self::BASE . '/terminals/' . self::TERMINAL_ID . '/links', self::target($http->lastCall()));
        $client->listTerminalAudit(self::TERMINAL_ID, ['cursor' => 'c']);
        $this->assertSame(self::BASE . '/terminals/' . self::TERMINAL_ID . '/audit?cursor=c', self::target($http->lastCall()));

        foreach ($http->calls as $call) {
            $this->assertSame('OK', $this->verdict($call));
        }
    }

    // ── Devices ─────────────────────────────────────────────────────────────

    public function testDeviceRoutes(): void
    {
        [$client, $http] = $this->make();
        $assignment = ['id' => 'a1', 'deviceId' => self::DEVICE_ID, 'store' => ['id' => self::STORE_ID, 'code' => 'IST-01', 'name' => 'Kadikoy']];
        $http->pushJson(200, self::page([self::deviceJson()], false, null, 1));
        $http->pushJson(201, self::envelope(['device' => self::deviceJson(), 'replayed' => false], 201));
        $http->pushJson(200, self::envelope(['device' => self::deviceJson()]));
        $http->pushJson(200, self::envelope(['device' => self::deviceJson(['displayName' => null])]));
        $http->pushJson(200, self::envelope(['device' => self::deviceJson(), 'assignment' => $assignment, 'previousAssignmentId' => null]));
        $http->pushJson(200, self::envelope(['device' => self::deviceJson(), 'closedAssignment' => $assignment, 'replayed' => false]));
        $http->pushJson(200, self::envelope(['device' => self::deviceJson(['lifecycleStatus' => 'SUSPENDED'])]));
        $http->pushJson(200, self::envelope(['device' => self::deviceJson(['lifecycleStatus' => 'RETIRED']), 'replayed' => true]));
        $http->pushJson(200, self::page([$assignment]));
        $http->pushJson(200, self::page([]));

        $page = $client->listDevices(['storeId' => self::STORE_ID, 'lifecycleStatus' => 'ACTIVE', 'includeTotal' => true]);
        $this->assertSame(['includeTotal' => 'true', 'storeId' => self::STORE_ID, 'lifecycleStatus' => 'ACTIVE'], self::queryOf($http->lastCall()));
        $this->assertSame(1, $page->totalCount());

        $created = $client->createDevice([
            'vendorCode' => 'PAVO', 'deviceModelId' => 'm1', 'serialNumber' => 'PAV00012345', 'integrationChannel' => 'APP2APP',
            'externalRef' => 'POS-1',
        ]);
        $this->assertSame(self::BASE . '/devices', self::target($http->lastCall()));
        $this->assertMatchesRegularExpression(self::UUID_V4, $http->lastCall()['headers']['Idempotency-Key']);
        $this->assertSame(201, $created->statusCode());

        $this->assertSame(self::DEVICE_ID, $client->getDevice(self::DEVICE_ID)['id']);

        $client->updateDevice(self::DEVICE_ID, ['rowVersion' => 1, 'displayName' => null, 'appMode' => null]);
        $this->assertSame(['rowVersion' => 1, 'displayName' => null, 'appMode' => null], self::bodyOf($http->lastCall()));

        $assigned = $client->assignDevice(self::DEVICE_ID, ['rowVersion' => 2, 'storeId' => self::STORE_ID, 'registerId' => null, 'reasonCode' => 'INITIAL_SETUP']);
        $this->assertSame(self::BASE . '/devices/' . self::DEVICE_ID . '/assignment', self::target($http->lastCall()));
        $this->assertArrayNotHasKey('Idempotency-Key', $http->lastCall()['headers']);
        $this->assertSame('a1', $assigned['assignment']['id']);

        $stored = $client->closeDeviceAssignment(self::DEVICE_ID, ['rowVersion' => 3, 'reasonCode' => 'MOVED_TO_STORAGE'], self::KEY);
        $this->assertSame(self::BASE . '/devices/' . self::DEVICE_ID . '/assignment/close', self::target($http->lastCall()));
        $this->assertSame(self::KEY, $http->lastCall()['headers']['Idempotency-Key']);
        $this->assertSame('a1', $stored->data()['closedAssignment']['id']);

        $client->changeDeviceLifecycle(self::DEVICE_ID, ['rowVersion' => 4, 'target' => 'SUSPENDED', 'reasonCode' => 'MERCHANT_REQUEST']);
        $this->assertSame(self::BASE . '/devices/' . self::DEVICE_ID . '/lifecycle', self::target($http->lastCall()));

        $retired = $client->retireDevice(self::DEVICE_ID, ['rowVersion' => 5, 'reasonCode' => 'END_OF_LIFE']);
        $this->assertSame(self::BASE . '/devices/' . self::DEVICE_ID . '/retire', self::target($http->lastCall()));
        $this->assertTrue($retired->replayed());
        $this->assertSame('RETIRED', $retired->record()['lifecycleStatus']);

        $client->listDeviceAssignments(self::DEVICE_ID);
        $this->assertSame(self::BASE . '/devices/' . self::DEVICE_ID . '/assignments', self::target($http->lastCall()));
        $client->listDeviceAudit(self::DEVICE_ID, ['limit' => 100]);
        $this->assertSame(self::BASE . '/devices/' . self::DEVICE_ID . '/audit?limit=100', self::target($http->lastCall()));

        foreach ($http->calls as $call) {
            $this->assertSame('OK', $this->verdict($call));
        }
    }

    public function testIteratorFollowsTheCursor(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(200, self::page([self::deviceJson()], true, 'cur_2'));
        $http->pushJson(200, self::page([self::deviceJson(['id' => 'd2'])]));

        $ids = [];
        foreach ($client->iterateDevices(['limit' => 1]) as $device) {
            $ids[] = $device['id'];
        }

        $this->assertSame([self::DEVICE_ID, 'd2'], $ids);
        $this->assertSame('cur_2', self::queryOf($http->calls[1])['cursor']);
    }

    // ── Request checks, errors ──────────────────────────────────────────────

    /** @return array<string,array{0:\Closure(PhysicalPosManagementClient):mixed}> */
    public static function badRequests(): array
    {
        return [
            'unknown store field' => [static fn (PhysicalPosManagementClient $c) => $c->createStore(['code' => 'A', 'name' => 'B', 'phone' => 'x'])],
            'environment' => [static fn (PhysicalPosManagementClient $c) => $c->createTerminal(['code' => 'A', 'displayName' => 'B', 'environment' => 'LIVE'])],
            'missing name' => [static fn (PhysicalPosManagementClient $c) => $c->createRegister(self::STORE_ID, ['code' => 'A'])],
            'rowVersion as string' => [static fn (PhysicalPosManagementClient $c) => $c->updateStore(self::STORE_ID, ['rowVersion' => '3'])],
            'missing rowVersion' => [static fn (PhysicalPosManagementClient $c) => $c->archiveStore(self::STORE_ID, ['reasonCode' => 'OTHER'])],
            'empty required code' => [static fn (PhysicalPosManagementClient $c) => $c->createRegister(self::STORE_ID, ['code' => '', 'name' => 'B'])],
            'rowVersion 0' => [static fn (PhysicalPosManagementClient $c) => $c->updateStore(self::STORE_ID, ['rowVersion' => 0])],
            'empty key' => [static fn (PhysicalPosManagementClient $c) => $c->createStore(['code' => 'A', 'name' => 'B'], '')],
            'blank key' => [static fn (PhysicalPosManagementClient $c) => $c->createStore(['code' => 'A', 'name' => 'B'], '          ')],
            'padded key' => [static fn (PhysicalPosManagementClient $c) => $c->createStore(['code' => 'A', 'name' => 'B'], ' ' . self::KEY)],
            'dot id' => [static fn (PhysicalPosManagementClient $c) => $c->getStore('.')],
            'dot-dot id' => [static fn (PhysicalPosManagementClient $c) => $c->getDevice('..')],
            'currencies not a list' => [static fn (PhysicalPosManagementClient $c) => $c->addTerminalAcquirer(self::TERMINAL_ID, [
                'vendorAcquirerRef' => 'B1', 'merchantNumber' => 'M1', 'terminalNumber' => 'T1', 'currencies' => 'TRY',
            ])],
            'bad key' => [static fn (PhysicalPosManagementClient $c) => $c->createDevice([
                'vendorCode' => 'PAVO', 'deviceModelId' => 'm1', 'serialNumber' => 'S', 'integrationChannel' => 'APP2APP',
            ], 'bad key')],
            'unknown filter' => [static fn (PhysicalPosManagementClient $c) => $c->listStores(['environment' => 'LIVE'])],
            'history filter' => [static fn (PhysicalPosManagementClient $c) => $c->listDeviceAudit(self::DEVICE_ID, ['includeTotal' => true])],
            'includeTotal as string' => [static fn (PhysicalPosManagementClient $c) => $c->listDevices(['includeTotal' => 'true'])],
            'catalog filter' => [static fn (PhysicalPosManagementClient $c) => $c->listDeviceModels(['q' => 'x'])],
            'empty id' => [static fn (PhysicalPosManagementClient $c) => $c->getDevice('')],
        ];
    }

    #[DataProvider('badRequests')]
    public function testMalformedRequestsAreRefusedBeforeSending(\Closure $call): void
    {
        [$client, $http] = $this->make();
        try {
            $call($client);
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException $error) {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testCardDataKeyInAManagementBodyIsRefused(): void
    {
        [$client, $http] = $this->make();
        try {
            $client->createDevice([
                'vendorCode' => 'PAVO', 'deviceModelId' => 'm1', 'serialNumber' => 'S', 'integrationChannel' => 'APP2APP', 'cardNumber' => 'x',
            ]);
            $this->fail('expected PhysicalPosCardDataRejectedException');
        } catch (PhysicalPosCardDataRejectedException $error) {
            $this->assertSame('cardNumber', $error->getField());
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testRateLimitIsTypedAndManagementWritesAreNeverOutcomeUnknown(): void
    {
        [$client, $http] = $this->make();
        $http->pushJson(429, ['status' => 'failure', 'code' => 'RATE_LIMITED', 'message' => 'Too many', 'details' => ['retryAfterSeconds' => 9]]);
        $http->pushJson(503, ['status' => 'failure', 'code' => 'physical_pos.service.busy', 'message' => 'Busy']);

        try {
            $client->createStore(['code' => 'A', 'name' => 'B'], self::KEY);
            $this->fail('expected PhysicalPosRateLimitException');
        } catch (PhysicalPosRateLimitException $error) {
            $this->assertSame(9, $error->getRetryAfter());
        }
        try {
            $client->createStore(['code' => 'A', 'name' => 'B'], self::KEY);
            $this->fail('expected ApiException');
        } catch (PhysicalPosOutcomeUnknownException $error) {
            $this->fail('outcome unknown is reserved for payment writes');
        } catch (ApiException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertSame('physical_pos.service.busy', $error->getErrorCode());
        }
    }
}
