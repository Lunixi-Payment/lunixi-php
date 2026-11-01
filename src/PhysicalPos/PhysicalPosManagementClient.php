<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

use Generator;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Physical POS management — the stores, registers, logical terminals (with
 * their bank rows and device link) and devices your payments run on, plus the
 * catalog and an overview (`/api/v1/physical-pos`, product PHYSICAL_POS).
 *
 *   getOverview                                   GET   /api/v1/physical-pos/overview
 *   listVendors / listDeviceModels / listAcquirers GET  …/catalog/{vendors|device-models|acquirers}
 *   listStores / iterateStores                    GET   …/stores
 *   createStore                                   POST  …/stores                                   (201, Idempotency-Key)
 *   getStore / updateStore                        GET / PATCH …/stores/{storeId}
 *   archiveStore / restoreStore                   POST  …/stores/{storeId}/{archive|restore}
 *   listStoreAudit / iterateStoreAudit            GET   …/stores/{storeId}/audit
 *   listRegisters / iterateRegisters              GET   …/stores/{storeId}/registers
 *   createRegister                                POST  …/stores/{storeId}/registers               (201, Idempotency-Key)
 *   updateRegister                                PATCH …/registers/{registerId}
 *   archiveRegister / restoreRegister             POST  …/registers/{registerId}/{archive|restore}
 *   listRegisterAudit / iterateRegisterAudit      GET   …/registers/{registerId}/audit
 *   listTerminals / iterateTerminals              GET   …/terminals
 *   createTerminal                                POST  …/terminals                                (201, Idempotency-Key)
 *   getTerminal / updateTerminal                  GET / PATCH …/terminals/{terminalId}
 *   closeTerminal                                 POST  …/terminals/{terminalId}/close
 *   listTerminalAcquirers / iterate…              GET   …/terminals/{terminalId}/acquirers
 *   addTerminalAcquirer                           POST  …/terminals/{terminalId}/acquirers         (201, Idempotency-Key)
 *   updateTerminalAcquirer                        PATCH …/terminal-acquirers/{terminalAcquirerId}
 *   changeTerminalDevice                          POST  …/terminals/{terminalId}/device-link
 *   closeTerminalDeviceLink                       POST  …/terminals/{terminalId}/device-link/close (Idempotency-Key)
 *   listTerminalLinks / listTerminalAudit (+iterate) GET …/terminals/{terminalId}/{links|audit}
 *   listDevices / iterateDevices                  GET   …/devices
 *   createDevice                                  POST  …/devices                                  (201, Idempotency-Key)
 *   getDevice / updateDevice                      GET / PATCH …/devices/{deviceId}
 *   assignDevice                                  POST  …/devices/{deviceId}/assignment
 *   closeDeviceAssignment                         POST  …/devices/{deviceId}/assignment/close      (Idempotency-Key)
 *   changeDeviceLifecycle                         POST  …/devices/{deviceId}/lifecycle
 *   retireDevice                                  POST  …/devices/{deviceId}/retire                (Idempotency-Key)
 *   listDeviceAssignments / listDeviceAudit (+iterate) GET …/devices/{deviceId}/{assignments|audit}
 *
 * Every call is signed. Reads need `physical_pos:view`, writes
 * `physical_pos:manage` (which cannot move money). A TEST key reads only.
 * Updates and state changes carry the record's last `rowVersion` (409
 * `physical_pos.version_conflict` when stale). Reason codes are closed sets
 * checked by the gateway (400 `physical_pos.input.reason_code_invalid`).
 *
 * The routes marked Idempotency-Key require one: when you pass null, a random
 * UUID is generated for the call and returned in PhysicalPosWriteResult. A key
 * you pass is sent as given (never trimmed); an empty or blank key is refused.
 * Use a new key for every operation; the same key and body answer the first
 * result (`replayed: true`).
 *
 * Empty values are never sent: a field left out, an empty string on an
 * optional field and null on a field that cannot be cleared are dropped; null
 * on a clearable field (e.g. `city`, `externalRef`, `label`) clears it.
 *
 * Records are returned as the gateway sends them (associative arrays, e.g.
 * `store`: id, code, name, city, district, addressLine, timezone, storeStatus,
 * externalRef, rowVersion, createdAt, updatedAt); an empty value is null.
 * Device identity (activation codes, device keys) is not part of this client.
 */
final class PhysicalPosManagementClient
{
    /** Query filters each read accepts. */
    public const FILTERS = [
        'listDeviceModels' => ['vendorCode', 'deviceType', 'status'],
        'listStores' => ['limit', 'cursor', 'includeTotal', 'status', 'q'],
        'listRegisters' => ['limit', 'cursor', 'includeTotal', 'status'],
        'listTerminals' => ['limit', 'cursor', 'includeTotal', 'status', 'q'],
        'listTerminalAcquirers' => ['limit', 'cursor', 'includeTotal'],
        'listDevices' => [
            'limit', 'cursor', 'includeTotal', 'storeId', 'registerId', 'vendorCode', 'deviceModelId', 'deviceType',
            'lifecycleStatus', 'ownershipStatus', 'integrationChannel', 'q',
        ],
        'history' => ['limit', 'cursor'],
    ];

    private const BASE = '/api/v1/physical-pos';

    private const STATUS_CHANGE = ['rowVersion' => 'rowVersion', 'reasonCode' => 'string'];

    /** Body schema per request (field => type; `?` = null clears the field). */
    private const SCHEMAS = [
        'createRegister' => ['code' => 'string', 'name' => 'string'],
        'updateRegister' => ['rowVersion' => 'rowVersion', 'name' => 'string'],
        'updateTerminal' => ['rowVersion' => 'rowVersion', 'displayName' => 'string', 'vendorCode' => 'string'],
        'createStore' => ['code' => 'string', 'name' => 'string', 'city' => 'string', 'district' => 'string', 'addressLine' => 'string', 'timezone' => 'string', 'externalRef' => 'string'],
        'updateStore' => ['rowVersion' => 'rowVersion', 'name' => 'string', 'city' => '?string', 'district' => '?string', 'addressLine' => '?string', 'timezone' => 'string', 'externalRef' => '?string'],
        'createTerminal' => ['code' => 'string', 'displayName' => 'string', 'vendorCode' => 'string', 'virtualSerial' => 'string', 'vendorTerminalRef' => 'string'],
        'addTerminalAcquirer' => ['vendorAcquirerRef' => 'string', 'merchantNumber' => 'string', 'terminalNumber' => 'string', 'label' => 'string', 'currencies' => 'strings'],
        'updateTerminalAcquirer' => ['rowVersion' => 'rowVersion', 'label' => '?string', 'currencies' => 'strings', 'status' => 'string'],
        'changeTerminalDevice' => ['rowVersion' => 'rowVersion', 'deviceId' => 'string', 'reasonCode' => 'string'],
        'createDevice' => ['vendorCode' => 'string', 'deviceModelId' => 'string', 'serialNumber' => 'string', 'displayName' => 'string', 'externalRef' => 'string', 'storeId' => 'string', 'registerId' => 'string', 'integrationChannel' => 'string', 'channelVariant' => 'string', 'appMode' => 'string'],
        'updateDevice' => ['rowVersion' => 'rowVersion', 'displayName' => '?string', 'externalRef' => '?string', 'integrationChannel' => 'string', 'channelVariant' => '?string', 'appMode' => '?string'],
        'assignDevice' => ['rowVersion' => 'rowVersion', 'storeId' => 'string', 'registerId' => '?string', 'reasonCode' => 'string'],
        'changeDeviceLifecycle' => ['rowVersion' => 'rowVersion', 'target' => 'string', 'reasonCode' => 'string'],
    ];

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    // ── Overview, catalog ─────────────────────────────────────────────────────

    /**
     * Counts of stores, registers, terminals and devices (the key's
     * environment for terminals and devices). `devices` may be null.
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function getOverview(): array
    {
        return self::record($this->read('/overview'), 'overview');
    }

    /**
     * @return array<int,array<string,mixed>> `{code, displayName, status, fallbackImage}`.
     * @throws ApiException
     */
    public function listVendors(): array
    {
        return self::rows($this->read('/catalog/vendors'));
    }

    /**
     * @param array<string,mixed> $filters vendorCode, deviceType, status.
     * @return array<int,array<string,mixed>>
     * @throws ApiException
     */
    public function listDeviceModels(array $filters = []): array
    {
        return self::rows($this->read('/catalog/device-models', PhysicalPosRequest::query($filters, self::FILTERS['listDeviceModels'], 'listDeviceModels')));
    }

    /**
     * Banks a terminal row can point to (active only).
     *
     * @return array<int,array<string,mixed>> `{acquirerCode, displayName}`.
     * @throws ApiException
     */
    public function listAcquirers(): array
    {
        return self::rows($this->read('/catalog/acquirers'));
    }

    // ── Stores ────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $filters limit, cursor, includeTotal (bool; first page only), status (ACTIVE | ARCHIVED),
     *   q (code or name prefix).
     * @return PhysicalPosPage<array<string,mixed>> Stores with `registerCount` (active registers).
     * @throws ApiException
     */
    public function listStores(array $filters = []): PhysicalPosPage
    {
        return $this->page('/stores', $filters, 'listStores');
    }

    /**
     * @param array<string,mixed> $filters Same as listStores().
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateStores(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listStores($page), $filters);
    }

    /**
     * @param array<string,mixed> $request code, name, city?, district?, addressLine?, timezone?, externalRef?.
     * @throws ConfigurationException
     * @throws ApiException 409 `physical_pos.store.code_taken` / `store.external_ref_taken`.
     */
    public function createStore(array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'createStore', self::SCHEMAS['createStore'], ['code', 'name']);

        return $this->keyedWrite('/stores', $payload, $idempotencyKey, 'store', 201);
    }

    /**
     * @return array<string,mixed> `{store, registerSummary: {activeCount, archivedCount}, assignedDeviceCount}`.
     * @throws ApiException
     */
    public function getStore(string $storeId): array
    {
        return PhysicalPosRequest::data($this->read('/stores/' . PhysicalPosRequest::pathId($storeId, 'storeId')));
    }

    /**
     * A field left out is not changed; null clears city, district, addressLine or externalRef.
     *
     * @param array<string,mixed> $request rowVersion, name?, city?, district?, addressLine?, timezone?, externalRef?.
     * @return array<string,mixed> The store.
     * @throws ApiException
     */
    public function updateStore(string $storeId, array $request): array
    {
        $payload = $this->body($request, 'updateStore', self::SCHEMAS['updateStore'], ['rowVersion']);

        return self::record($this->write('PATCH', '/stores/' . PhysicalPosRequest::pathId($storeId, 'storeId'), $payload), 'store');
    }

    /**
     * Archives the store and its active registers in one step (409
     * `physical_pos.store.has_devices` while devices are assigned).
     *
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @return array<string,mixed> `{store, archivedRegisterCount}`.
     * @throws ApiException
     */
    public function archiveStore(string $storeId, array $request): array
    {
        return PhysicalPosRequest::data($this->statusChange('/stores/' . PhysicalPosRequest::pathId($storeId, 'storeId') . '/archive', $request, 'archiveStore'));
    }

    /**
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @return array<string,mixed> The store (its registers stay archived).
     * @throws ApiException
     */
    public function restoreStore(string $storeId, array $request): array
    {
        return self::record($this->statusChange('/stores/' . PhysicalPosRequest::pathId($storeId, 'storeId') . '/restore', $request, 'restoreStore'), 'store');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listStoreAudit(string $storeId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/stores/' . PhysicalPosRequest::pathId($storeId, 'storeId') . '/audit', $filters, 'history');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateStoreAudit(string $storeId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listStoreAudit($storeId, $page), $filters);
    }

    // ── Registers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $filters limit, cursor, includeTotal, status.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listRegisters(string $storeId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/stores/' . PhysicalPosRequest::pathId($storeId, 'storeId') . '/registers', $filters, 'listRegisters');
    }

    /**
     * @param array<string,mixed> $filters Same as listRegisters().
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateRegisters(string $storeId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listRegisters($storeId, $page), $filters);
    }

    /**
     * @param array<string,mixed> $request code, name.
     * @throws ApiException 409 `physical_pos.register.code_taken`, `physical_pos.store.archived`.
     */
    public function createRegister(string $storeId, array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'createRegister', self::SCHEMAS['createRegister'], ['code', 'name']);

        return $this->keyedWrite('/stores/' . PhysicalPosRequest::pathId($storeId, 'storeId') . '/registers', $payload, $idempotencyKey, 'register', 201);
    }

    /**
     * @param array<string,mixed> $request rowVersion, name?.
     * @return array<string,mixed> The register.
     * @throws ApiException
     */
    public function updateRegister(string $registerId, array $request): array
    {
        $payload = $this->body($request, 'updateRegister', self::SCHEMAS['updateRegister'], ['rowVersion']);

        return self::record($this->write('PATCH', '/registers/' . PhysicalPosRequest::pathId($registerId, 'registerId'), $payload), 'register');
    }

    /**
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @return array<string,mixed> The register.
     * @throws ApiException
     */
    public function archiveRegister(string $registerId, array $request): array
    {
        return self::record($this->statusChange('/registers/' . PhysicalPosRequest::pathId($registerId, 'registerId') . '/archive', $request, 'archiveRegister'), 'register');
    }

    /**
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @return array<string,mixed> The register.
     * @throws ApiException
     */
    public function restoreRegister(string $registerId, array $request): array
    {
        return self::record($this->statusChange('/registers/' . PhysicalPosRequest::pathId($registerId, 'registerId') . '/restore', $request, 'restoreRegister'), 'register');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listRegisterAudit(string $registerId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/registers/' . PhysicalPosRequest::pathId($registerId, 'registerId') . '/audit', $filters, 'history');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateRegisterAudit(string $registerId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listRegisterAudit($registerId, $page), $filters);
    }

    // ── Terminals ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $filters limit, cursor, includeTotal, status (ACTIVE | SUSPENDED | CLOSED), q.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listTerminals(array $filters = []): PhysicalPosPage
    {
        return $this->page('/terminals', $filters, 'listTerminals');
    }

    /**
     * @param array<string,mixed> $filters Same as listTerminals().
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateTerminals(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listTerminals($page), $filters);
    }

    /**
     * @param array<string,mixed> $request code, displayName, vendorCode?, virtualSerial?, vendorTerminalRef?.
     * @throws ApiException
     */
    public function createTerminal(array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'createTerminal', self::SCHEMAS['createTerminal'], ['code', 'displayName']);

        return $this->keyedWrite('/terminals', $payload, $idempotencyKey, 'terminal', 201);
    }

    /**
     * @return array<string,mixed> `{terminal, acquirers: {items, pageInfo}}`.
     * @throws ApiException
     */
    public function getTerminal(string $terminalId): array
    {
        return PhysicalPosRequest::data($this->read('/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId')));
    }

    /**
     * @param array<string,mixed> $request rowVersion, displayName?, vendorCode? (once, before any bank row).
     * @return array<string,mixed> The terminal.
     * @throws ApiException
     */
    public function updateTerminal(string $terminalId, array $request): array
    {
        $payload = $this->body($request, 'updateTerminal', self::SCHEMAS['updateTerminal'], ['rowVersion']);

        return self::record($this->write('PATCH', '/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId'), $payload), 'terminal');
    }

    /**
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @return array<string,mixed> The terminal.
     * @throws ApiException
     */
    public function closeTerminal(string $terminalId, array $request): array
    {
        return self::record($this->statusChange('/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId') . '/close', $request, 'closeTerminal'), 'terminal');
    }

    /**
     * Bank rows of a terminal (`id` is the `terminalAcquirerId` a payment may name).
     *
     * @param array<string,mixed> $filters limit, cursor, includeTotal.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listTerminalAcquirers(string $terminalId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId') . '/acquirers', $filters, 'listTerminalAcquirers');
    }

    /**
     * @param array<string,mixed> $filters Same as listTerminalAcquirers().
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateTerminalAcquirers(string $terminalId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listTerminalAcquirers($terminalId, $page), $filters);
    }

    /**
     * @param array<string,mixed> $request vendorAcquirerRef, merchantNumber, terminalNumber, label?, currencies (string[]).
     * @throws ApiException
     */
    public function addTerminalAcquirer(string $terminalId, array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'addTerminalAcquirer', self::SCHEMAS['addTerminalAcquirer'], ['vendorAcquirerRef', 'merchantNumber', 'terminalNumber', 'currencies']);

        return $this->keyedWrite('/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId') . '/acquirers', $payload, $idempotencyKey, 'acquirer', 201);
    }

    /**
     * @param array<string,mixed> $request rowVersion, label? (null clears), currencies?, status?.
     * @return array<string,mixed> The bank row.
     * @throws ApiException
     */
    public function updateTerminalAcquirer(string $terminalAcquirerId, array $request): array
    {
        $payload = $this->body($request, 'updateTerminalAcquirer', self::SCHEMAS['updateTerminalAcquirer'], ['rowVersion']);

        return self::record($this->write('PATCH', '/terminal-acquirers/' . PhysicalPosRequest::pathId($terminalAcquirerId, 'terminalAcquirerId'), $payload), 'acquirer');
    }

    /**
     * Links the terminal to a device (closing the previous link).
     *
     * @param array<string,mixed> $request rowVersion, deviceId, reasonCode.
     * @return array<string,mixed> `{terminal, link}`.
     * @throws ApiException
     */
    public function changeTerminalDevice(string $terminalId, array $request): array
    {
        $payload = $this->body($request, 'changeTerminalDevice', self::SCHEMAS['changeTerminalDevice'], ['rowVersion', 'deviceId', 'reasonCode']);

        return PhysicalPosRequest::data($this->write('POST', '/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId') . '/device-link', $payload));
    }

    /**
     * Unlinks the terminal's device. `data()['link']` is the closed link or null.
     *
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @throws ApiException
     */
    public function closeTerminalDeviceLink(string $terminalId, array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'closeTerminalDeviceLink', self::STATUS_CHANGE, ['rowVersion', 'reasonCode']);

        return $this->keyedWrite('/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId') . '/device-link/close', $payload, $idempotencyKey, 'terminal', 200);
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listTerminalLinks(string $terminalId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId') . '/links', $filters, 'history');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateTerminalLinks(string $terminalId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listTerminalLinks($terminalId, $page), $filters);
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listTerminalAudit(string $terminalId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/terminals/' . PhysicalPosRequest::pathId($terminalId, 'terminalId') . '/audit', $filters, 'history');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateTerminalAudit(string $terminalId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listTerminalAudit($terminalId, $page), $filters);
    }

    // ── Devices ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $filters limit, cursor, includeTotal, storeId, registerId, vendorCode, deviceModelId,
     *   deviceType, lifecycleStatus, ownershipStatus, integrationChannel, q (serial / name / external reference prefix).
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listDevices(array $filters = []): PhysicalPosPage
    {
        return $this->page('/devices', $filters, 'listDevices');
    }

    /**
     * @param array<string,mixed> $filters Same as listDevices().
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateDevices(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listDevices($page), $filters);
    }

    /**
     * @param array<string,mixed> $request vendorCode, deviceModelId, serialNumber, integrationChannel, displayName?,
     *   externalRef? (a payment can target the device by it), storeId?, registerId?, channelVariant?, appMode?.
     * @throws ApiException
     */
    public function createDevice(array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'createDevice', self::SCHEMAS['createDevice'], ['vendorCode', 'deviceModelId', 'serialNumber', 'integrationChannel']);

        return $this->keyedWrite('/devices', $payload, $idempotencyKey, 'device', 201);
    }

    /**
     * @return array<string,mixed> The device (detail). Only this read fills
     *   `presence`: connectionState (ONLINE | OFFLINE | UNKNOWN), lastSeenAt,
     *   stateChangedAt, offlineReason, appVersion, appHealthy,
     *   paymentAppReachable, readyForPayment, notReadyReason, source,
     *   wakePaths, busy. A payment on a device that is not ready gets 409
     *   `physical_pos.payment.device_offline` (device-side reason) or 503
     *   `physical_pos.presence.unavailable`; write results carry `presence: null`.
     * @throws ApiException
     */
    public function getDevice(string $deviceId): array
    {
        return self::record($this->read('/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId')), 'device');
    }

    /**
     * @param array<string,mixed> $request rowVersion, displayName?, externalRef?, integrationChannel?, channelVariant?,
     *   appMode? (null clears displayName, externalRef, channelVariant, appMode).
     * @return array<string,mixed> The device.
     * @throws ApiException
     */
    public function updateDevice(string $deviceId, array $request): array
    {
        $payload = $this->body($request, 'updateDevice', self::SCHEMAS['updateDevice'], ['rowVersion']);

        return self::record($this->write('PATCH', '/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId'), $payload), 'device');
    }

    /**
     * Assigns the device to a store (and register), closing the previous assignment.
     *
     * @param array<string,mixed> $request rowVersion, storeId, registerId? (null = store only), reasonCode.
     * @return array<string,mixed> `{device, assignment, previousAssignmentId}`.
     * @throws ApiException
     */
    public function assignDevice(string $deviceId, array $request): array
    {
        $payload = $this->body($request, 'assignDevice', self::SCHEMAS['assignDevice'], ['rowVersion', 'storeId', 'reasonCode']);

        return PhysicalPosRequest::data($this->write('POST', '/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId') . '/assignment', $payload));
    }

    /**
     * Moves the device to storage (closes its assignment). `data()['closedAssignment']`.
     *
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @throws ApiException
     */
    public function closeDeviceAssignment(string $deviceId, array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'closeDeviceAssignment', self::STATUS_CHANGE, ['rowVersion', 'reasonCode']);

        return $this->keyedWrite('/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId') . '/assignment/close', $payload, $idempotencyKey, 'device', 200);
    }

    /**
     * @param array<string,mixed> $request rowVersion, target (ACTIVE | SUSPENDED | MAINTENANCE), reasonCode.
     * @return array<string,mixed> The device.
     * @throws ApiException
     */
    public function changeDeviceLifecycle(string $deviceId, array $request): array
    {
        $payload = $this->body($request, 'changeDeviceLifecycle', self::SCHEMAS['changeDeviceLifecycle'], ['rowVersion', 'target', 'reasonCode']);

        return self::record($this->write('POST', '/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId') . '/lifecycle', $payload), 'device');
    }

    /**
     * Retires the device for good (409 `physical_pos.device.payment_active` while it has an active payment).
     *
     * @param array<string,mixed> $request rowVersion, reasonCode.
     * @throws ApiException
     */
    public function retireDevice(string $deviceId, array $request, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $payload = $this->body($request, 'retireDevice', self::STATUS_CHANGE, ['rowVersion', 'reasonCode']);

        return $this->keyedWrite('/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId') . '/retire', $payload, $idempotencyKey, 'device', 200);
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listDeviceAssignments(string $deviceId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId') . '/assignments', $filters, 'history');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateDeviceAssignments(string $deviceId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listDeviceAssignments($deviceId, $page), $filters);
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listDeviceAudit(string $deviceId, array $filters = []): PhysicalPosPage
    {
        return $this->page('/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId') . '/audit', $filters, 'history');
    }

    /**
     * @param array<string,mixed> $filters limit, cursor.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateDeviceAudit(string $deviceId, array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listDeviceAudit($deviceId, $page), $filters);
    }

    /**
     * Body field names each write accepts (`statusChange` = archive, restore,
     * close, device-link close, storage, retirement). Anything else is refused
     * before sending.
     *
     * @return array<string,string[]>
     */
    public static function requestFields(): array
    {
        $fields = ['statusChange' => array_keys(self::STATUS_CHANGE)];
        foreach (self::SCHEMAS as $name => $schema) {
            $fields[$name] = array_keys($schema);
        }

        return $fields;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $request
     * @param array<string,string> $schema
     * @param string[] $required
     * @return array<string,mixed>
     */
    private function body(array $request, string $operation, array $schema, array $required): array
    {
        return PhysicalPosRequest::body($request, $schema, $required, $operation . ' request');
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function statusChange(string $path, array $request, string $operation): array
    {
        return $this->write('POST', $path, $this->body($request, $operation, self::STATUS_CHANGE, ['rowVersion', 'reasonCode']));
    }

    /**
     * @param array<string,mixed> $filters
     * @return PhysicalPosPage<array<string,mixed>>
     */
    private function page(string $path, array $filters, string $route): PhysicalPosPage
    {
        return new PhysicalPosPage($this->read($path, PhysicalPosRequest::query($filters, self::FILTERS[$route], $route)));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function keyedWrite(string $path, array $payload, ?string $idempotencyKey, string $recordKey, int $newStatus): PhysicalPosWriteResult
    {
        $key = PhysicalPosRequest::idempotencyKey($idempotencyKey);
        $response = $this->write('POST', $path, $payload, $key);
        $data = PhysicalPosRequest::data($response);
        $replayed = ($data['replayed'] ?? false) === true;

        return new PhysicalPosWriteResult($data, $recordKey, PhysicalPosRequest::statusCode($response, $replayed ? 200 : $newStatus), $key);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function write(string $method, string $path, array $payload, ?string $idempotencyKey = null): array
    {
        $options = ['stepUp' => true];
        if ($idempotencyKey !== null) {
            $options['idempotencyKey'] = $idempotencyKey;
        }
        try {
            return $this->api->request($method, self::BASE . $path, $payload, $options);
        } catch (ApiException $error) {
            throw PhysicalPosRequest::mapError($error);
        }
    }

    /**
     * @param array<string,string|int> $query
     * @return array<string,mixed>
     */
    private function read(string $path, array $query = []): array
    {
        $options = ['stepUp' => true];
        if ($query !== []) {
            $options['query'] = $query;
        }
        try {
            return $this->api->request('GET', self::BASE . $path, null, $options);
        } catch (ApiException $error) {
            throw PhysicalPosRequest::mapError($error);
        }
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function record(array $response, string $key): array
    {
        $value = PhysicalPosRequest::data($response)[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Catalog answers are bare lists in `data`.
     *
     * @param array<string,mixed> $response
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $response): array
    {
        $data = $response['data'] ?? null;

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
}
