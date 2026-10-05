<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Wallet;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\HttpResponse;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\SignatureGuardStandIn;
use Lunixi\Sdk\Wallet\WalletAccounts;
use Lunixi\Sdk\Wallet\WalletAccountType;
use Lunixi\Sdk\Wallet\WalletBulkPayouts;
use Lunixi\Sdk\Wallet\WalletBulkTargetType;
use Lunixi\Sdk\Wallet\WalletClient;
use Lunixi\Sdk\Wallet\WalletCorporate;
use Lunixi\Sdk\Wallet\WalletCorporateAccountStatus;
use Lunixi\Sdk\Wallet\WalletDeposits;
use Lunixi\Sdk\Wallet\WalletEndUsers;
use Lunixi\Sdk\Wallet\WalletEndUserStatus;
use Lunixi\Sdk\Wallet\WalletFees;
use Lunixi\Sdk\Wallet\WalletInvoker;
use Lunixi\Sdk\Wallet\WalletKycLevel;
use Lunixi\Sdk\Wallet\WalletOperations;
use Lunixi\Sdk\Wallet\WalletOtpChannel;
use Lunixi\Sdk\Wallet\WalletPayments;
use Lunixi\Sdk\Wallet\WalletPrograms;
use Lunixi\Sdk\Wallet\WalletQr;
use Lunixi\Sdk\Wallet\WalletQrImage;
use Lunixi\Sdk\Wallet\WalletQrImageFormat;
use Lunixi\Sdk\Wallet\WalletQrType;
use Lunixi\Sdk\Wallet\WalletRoutes;
use Lunixi\Sdk\Wallet\WalletTopups;
use Lunixi\Sdk\Wallet\WalletTransfers;
use Lunixi\Sdk\Wallet\WalletWithdrawals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WalletClientTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';

    private const PROGRAM = '10000000-0000-4000-8000-000000000001';
    private const END_USER = '10000000-0000-4000-8000-000000000002';
    private const ACCOUNT = '10000000-0000-4000-8000-000000000003';
    private const WALLET = '10000000-0000-4000-8000-000000000004';
    private const OPERATION = '10000000-0000-4000-8000-000000000005';
    private const CORPORATE = '10000000-0000-4000-8000-000000000006';
    private const QR = '10000000-0000-4000-8000-000000000007';
    private const BATCH = '10000000-0000-4000-8000-000000000008';
    private const BUSINESS = '10000000-0000-4000-8000-000000000009';

    /** The sub-clients reachable from WalletClient, by property name. */
    private const SUB_CLIENTS = [
        'programs' => WalletPrograms::class,
        'endUsers' => WalletEndUsers::class,
        'wallets' => WalletAccounts::class,
        'deposits' => WalletDeposits::class,
        'corporate' => WalletCorporate::class,
        'fees' => WalletFees::class,
        'transfers' => WalletTransfers::class,
        'withdrawals' => WalletWithdrawals::class,
        'operations' => WalletOperations::class,
        'topups' => WalletTopups::class,
        'payments' => WalletPayments::class,
        'qr' => WalletQr::class,
        'bulkPayouts' => WalletBulkPayouts::class,
    ];

    /** Raw 32-byte Ed25519 public key of the client under test. */
    private string $publicKey = '';

    /** @return array{0:WalletClient,1:FakeHttpClient,2:ApiClient} */
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
        $api = new ApiClient($config, $signer, new TokenManager($config, $signer, $http, $store), $http);

        return [new WalletClient($api), $http, $api];
    }

    /** @param array{method:string,url:string,headers:array<string,string>,body:?string} $call */
    private function signatureGuardVerdict(array $call): string
    {
        return SignatureGuardStandIn::verdict($call, $this->publicKey, self::BASE_URL);
    }

    /** @return array<string,mixed> */
    private static function envelope(array $data, array $extra = []): array
    {
        return array_merge(['status' => 'success', 'code' => 'SUCCESS', 'data' => $data, 'requestId' => 'req_1'], $extra);
    }

    /** @return array<string,mixed> */
    private static function w2wQuote(array $overrides = []): array
    {
        return array_merge([
            'endUserId' => self::END_USER,
            'sourceWalletAccountId' => self::ACCOUNT,
            'amount' => '12550',
            'currency' => 'TRY',
            'destinationWalletNo' => '5001234567',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function ibanQuote(array $overrides = []): array
    {
        return array_merge([
            'endUserId' => self::END_USER,
            'sourceWalletAccountId' => self::ACCOUNT,
            'beneficiaryName' => 'Ada Yilmaz',
            'iban' => 'TR330006100519786457841326',
            'amount' => '50000',
            'currency' => 'TRY',
        ], $overrides);
    }

    /** @return array<string,string> */
    private static function completion(): array
    {
        return ['endUserId' => self::END_USER, 'integrityHash' => 'ih_1', 'nonce' => 'n_1', 'otpCode' => '123456'];
    }

    /**
     * One valid call of every public wallet method, keyed `Class::method`.
     *
     * @return array<string,callable():mixed>
     */
    private static function invocations(WalletClient $w): array
    {
        return [
            'WalletClient::ping' => static fn () => $w->ping(),

            'WalletPrograms::listMine' => static fn () => $w->programs->listMine(),
            'WalletPrograms::setCollectionAnchor' => static fn () => $w->programs->setCollectionAnchor(self::PROGRAM, ['collectionEndUserId' => self::END_USER, 'expectedVersion' => 3]),

            'WalletEndUsers::create' => static fn () => $w->endUsers->create(['programId' => self::PROGRAM, 'accountType' => WalletAccountType::PERSONAL, 'phone' => '+905350000000', 'externalCustomerId' => 'cust-1'], 'eu-create-cust-1'),
            'WalletEndUsers::list' => static fn () => $w->endUsers->list(['programId' => self::PROGRAM, 'limit' => 20]),
            'WalletEndUsers::iterate' => static fn () => iterator_to_array($w->endUsers->iterate(['status' => 'ACTIVE'])),
            'WalletEndUsers::get' => static fn () => $w->endUsers->get(self::END_USER),
            'WalletEndUsers::getByExternalId' => static fn () => $w->endUsers->getByExternalId('cust-1', self::PROGRAM),
            'WalletEndUsers::freeze' => static fn () => $w->endUsers->freeze(self::END_USER, ['reason' => 'fraud review'], 'eu-freeze-1'),
            'WalletEndUsers::getBalances' => static fn () => $w->endUsers->getBalances(self::END_USER),
            'WalletEndUsers::getKycLevel' => static fn () => $w->endUsers->getKycLevel(self::END_USER),
            'WalletEndUsers::updateKycLevel' => static fn () => $w->endUsers->updateKycLevel(self::END_USER, ['kycLevel' => WalletKycLevel::SUBSTANTIAL, 'sourceRef' => 'kyc-session-9'], 'eu-kyc-9'),
            'WalletEndUsers::getLimits' => static fn () => $w->endUsers->getLimits(self::END_USER, ['operationType' => 'W2W', 'currency' => 'TRY']),
            'WalletEndUsers::createWallet' => static fn () => $w->endUsers->createWallet(self::END_USER, ['name' => 'Savings', 'currencies' => ['TRY']], 'eu-wallet-1'),

            'WalletAccounts::createAccount' => static fn () => $w->wallets->createAccount(self::WALLET, 'USD', 'acct-usd-1'),
            'WalletAccounts::lookup' => static fn () => $w->wallets->lookup(['programId' => self::PROGRAM, 'walletNo' => '5001234567']),

            'WalletDeposits::listInstructions' => static fn () => $w->deposits->listInstructions(self::END_USER),
            'WalletDeposits::createInstruction' => static fn () => $w->deposits->createInstruction(self::END_USER, ['walletAccountId' => self::ACCOUNT]),
            'WalletDeposits::simulateInboundCredit' => static fn () => $w->deposits->simulateInboundCredit(['programId' => self::PROGRAM, 'railId' => 'rail-1', 'amount' => 100000, 'currency' => 'TRY', 'rawReference' => 'W5001234567']),

            'WalletCorporate::create' => static fn () => $w->corporate->create(['programId' => self::PROGRAM, 'code' => 'ACME', 'name' => 'Acme A.S.', 'ownerEndUserId' => self::END_USER], 'corp-acme'),
            'WalletCorporate::list' => static fn () => $w->corporate->list(['programId' => self::PROGRAM]),
            'WalletCorporate::iterate' => static fn () => iterator_to_array($w->corporate->iterate()),
            'WalletCorporate::importEmployees' => static fn () => $w->corporate->importEmployees(self::CORPORATE, [['phone' => '+905350000001', 'displayName' => 'Can']], 'corp-import-1'),
            'WalletCorporate::offboardEmployees' => static fn () => $w->corporate->offboardEmployees(self::CORPORATE, [['externalCustomerId' => 'emp-7']], ['reason' => 'left'], 'corp-offboard-1'),

            'WalletFees::quote' => static fn () => $w->fees->quote(['programId' => self::PROGRAM, 'operationType' => 'W2W', 'currency' => 'TRY', 'amount' => '12550']),

            'WalletTransfers::quoteW2W' => static fn () => $w->transfers->quoteW2W(self::w2wQuote()),
            'WalletTransfers::initiateW2W' => static fn () => $w->transfers->initiateW2W(self::w2wQuote(['otpChannel' => WalletOtpChannel::SMS]), 'w2w-order-1042'),
            'WalletTransfers::completeW2W' => static fn () => $w->transfers->completeW2W(self::OPERATION, self::completion(), 'w2w-order-1042-complete'),
            'WalletTransfers::quoteW2Iban' => static fn () => $w->transfers->quoteW2Iban(self::ibanQuote()),
            'WalletTransfers::initiateW2Iban' => static fn () => $w->transfers->initiateW2Iban(self::ibanQuote(), 'w2iban-1'),
            'WalletTransfers::completeW2Iban' => static fn () => $w->transfers->completeW2Iban(self::OPERATION, self::completion(), 'w2iban-1-complete'),

            'WalletWithdrawals::quote' => static fn () => $w->withdrawals->quote(self::ibanQuote()),
            'WalletWithdrawals::initiate' => static fn () => $w->withdrawals->initiate(self::ibanQuote(), 'wd-1'),
            'WalletWithdrawals::complete' => static fn () => $w->withdrawals->complete(self::OPERATION, self::completion(), 'wd-1-complete'),
            'WalletWithdrawals::get' => static fn () => $w->withdrawals->get(self::OPERATION),

            'WalletOperations::list' => static fn () => $w->operations->list(['endUserId' => self::END_USER]),
            'WalletOperations::iterate' => static fn () => iterator_to_array($w->operations->iterate(['limit' => 50])),
            'WalletOperations::get' => static fn () => $w->operations->get(self::OPERATION),
            'WalletOperations::getReceipt' => static fn () => $w->operations->getReceipt(self::OPERATION),

            'WalletTopups::startCard' => static fn () => $w->topups->startCard(['creditWalletAccountId' => self::ACCOUNT, 'amount' => '25000', 'currency' => 'TRY', 'returnUrl' => 'https://shop.example.com/topup/return'], 'topup-1'),
            'WalletTopups::get' => static fn () => $w->topups->get(self::OPERATION),
            'WalletTopups::refund' => static fn () => $w->topups->refund(self::OPERATION, ['reason' => 'customer request', 'requesterPrincipal' => 'ops-1', 'approverPrincipal' => 'ops-2']),
            'WalletTopups::agent' => static fn () => $w->topups->agent(['agentId' => 'agent-1', 'creditWalletAccountId' => self::ACCOUNT, 'amount' => '10000', 'currency' => 'TRY'], 'agent-topup-1'),

            'WalletPayments::request' => static fn () => $w->payments->request(['amount' => '9900', 'currency' => 'TRY', 'paymentPurpose' => 'ORDER', 'buyerWalletNo' => '5001234567', 'orderRef' => 'ORD-1'], 'pay-ORD-1'),
            'WalletPayments::approve' => static fn () => $w->payments->approve(self::OPERATION, ['buyerEndUserId' => self::END_USER, 'integrityHash' => 'ih_1', 'nonce' => 'n_1'], 'pay-ORD-1-approve'),
            'WalletPayments::collect' => static fn () => $w->payments->collect(['code' => '123456789012', 'amount' => '4500', 'currency' => 'TRY'], 'collect-1'),
            'WalletPayments::get' => static fn () => $w->payments->get(self::OPERATION),
            'WalletPayments::getCommission' => static fn () => $w->payments->getCommission(self::BUSINESS),

            'WalletQr::create' => static fn () => $w->qr->create(['qrType' => WalletQrType::MERCHANT_DYNAMIC, 'currency' => 'TRY', 'amount' => '15000', 'metadata' => ['orderRef' => 'ORD-2']], 'qr-ORD-2'),
            'WalletQr::list' => static fn () => $w->qr->list(['qrType' => WalletQrType::P2P_STATIC]),
            'WalletQr::iterate' => static fn () => iterator_to_array($w->qr->iterate()),
            'WalletQr::get' => static fn () => $w->qr->get(self::QR),
            'WalletQr::image' => static fn () => $w->qr->image(self::QR, ['format' => WalletQrImageFormat::PNG, 'scale' => 4]),
            'WalletQr::revoke' => static fn () => $w->qr->revoke(self::QR),
            'WalletQr::parse' => static fn () => $w->qr->parse('000201010212...'),

            'WalletBulkPayouts::create' => static fn () => $w->bulkPayouts->create(['programId' => self::PROGRAM, 'sourceWalletAccountId' => self::ACCOUNT, 'currency' => 'TRY', 'items' => [['targetType' => WalletBulkTargetType::WALLET, 'targetRef' => '5001234567', 'amount' => 5000], ['targetType' => WalletBulkTargetType::IBAN, 'targetRef' => 'TR330006100519786457841326', 'amount' => '7500', 'beneficiaryName' => 'Ada Yilmaz']]], 'bulk-2026-10'),
            'WalletBulkPayouts::list' => static fn () => $w->bulkPayouts->list(['status' => 'DRAFT', 'limit' => 10]),
            'WalletBulkPayouts::get' => static fn () => $w->bulkPayouts->get(self::BATCH),
            'WalletBulkPayouts::listItems' => static fn () => $w->bulkPayouts->listItems(self::BATCH, ['status' => 'FAILED']),
            'WalletBulkPayouts::submit' => static fn () => $w->bulkPayouts->submit(self::BATCH),
            'WalletBulkPayouts::approve' => static fn () => $w->bulkPayouts->approve(self::BATCH),
            'WalletBulkPayouts::reject' => static fn () => $w->bulkPayouts->reject(self::BATCH, ['reason' => 'wrong amounts']),
            'WalletBulkPayouts::process' => static fn () => $w->bulkPayouts->process(self::BATCH),
            'WalletBulkPayouts::retryFailed' => static fn () => $w->bulkPayouts->retryFailed(self::BATCH),
        ];
    }

    /**
     * Runs every invocation against the fake transport.
     *
     * @return array<int,array{name:string, call:array{method:string,url:string,headers:array<string,string>,body:?string}, route:string}>
     */
    private function runAll(): array
    {
        [$wallet, $http] = $this->make();
        $records = [];
        foreach (self::invocations($wallet) as $name => $invoke) {
            $before = $http->callCount();
            $http->push($name === 'WalletQr::image'
                ? new HttpResponse(200, ['content-type' => 'image/png'], "\x89PNG")
                : new HttpResponse(200, [], (string) json_encode(self::envelope(['items' => [['id' => 'row_1']], 'nextCursor' => null]))));
            $invoke();
            $sent = array_slice($http->calls, $before);
            $this->assertCount(1, $sent, "{$name} sends exactly one request");
            $records[] = ['name' => $name, 'call' => $sent[0], 'route' => self::routeOf($sent[0])];
        }

        return $records;
    }

    /**
     * The WalletRoutes entry a recorded request matches: method plus path
     * template, `:name` standing for one path segment. Exactly one must match.
     *
     * @param array{method:string,url:string} $call
     */
    private static function routeOf(array $call): string
    {
        $path = (string) parse_url($call['url'], PHP_URL_PATH);
        $matches = [];
        foreach (WalletRoutes::ROUTES as $name => $route) {
            $pattern = '#^' . preg_replace('/:[A-Za-z]+/', '[^/]+', $route['path']) . '$#';
            if ($route['method'] === $call['method'] && preg_match($pattern, $path) === 1) {
                $matches[] = $name;
            }
        }
        self::assertCount(1, $matches, "{$call['method']} {$path} must match exactly one wallet route");

        return $matches[0];
    }

    /** @return array<string,mixed> */
    private static function bodyOf(array $call): array
    {
        return $call['body'] === null ? [] : (array) json_decode($call['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    // --- Locks over every method and every route ----------------------------------------

    /**
     * Lock: the invocation table covers every public wallet method. The
     * method list is read from the classes, so a method added later without
     * an entry here fails.
     */
    public function testTheLockCoversEveryPublicWalletMethod(): void
    {
        [$wallet] = $this->make();
        $public = [];
        foreach (array_merge([WalletClient::class], array_values(self::SUB_CLIENTS)) as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isConstructor() && !$method->isStatic()) {
                    $public[] = $reflection->getShortName() . '::' . $method->getName();
                }
            }
        }
        $covered = array_keys(self::invocations($wallet));
        sort($public);
        sort($covered);

        $this->assertSame($public, $covered);
    }

    public function testEverySubClientIsAPublicPropertyOfTheWalletClient(): void
    {
        [$wallet] = $this->make();
        foreach (self::SUB_CLIENTS as $property => $class) {
            $this->assertInstanceOf($class, $wallet->{$property});
        }
    }

    /** Lock: every method sends a request the gateway's SignatureGuard accepts (reads included). */
    public function testEveryWalletMethodSendsASignedRequest(): void
    {
        foreach ($this->runAll() as $record) {
            $call = $record['call'];
            $this->assertSame('mk_test_sk_kid_1', $call['headers']['X-Key-Id'] ?? null, $record['name']);
            $this->assertSame(
                'OK',
                $this->signatureGuardVerdict($call),
                "{$record['name']}: {$call['method']} {$call['url']} must carry a valid API-key signature"
            );
        }
    }

    /** Lock: the routes the methods actually hit are exactly the route table — all 60 of them. */
    public function testTheMethodsHitEveryRouteOfTheTable(): void
    {
        $hit = array_values(array_unique(array_column($this->runAll(), 'route')));
        $table = array_keys(WalletRoutes::ROUTES);
        sort($hit);
        sort($table);

        $this->assertCount(60, $table);
        $this->assertSame($table, $hit);
    }

    /** Lock: the Idempotency-Key header goes out on exactly the routes that require it. */
    public function testTheIdempotencyKeyIsSentExactlyOnTheRoutesThatRequireIt(): void
    {
        $withKey = 0;
        foreach ($this->runAll() as $record) {
            $required = WalletRoutes::ROUTES[$record['route']]['idempotencyKey'];
            $sent = isset($record['call']['headers']['Idempotency-Key']);
            $this->assertSame($required, $sent, "{$record['route']}: Idempotency-Key " . ($required ? 'required' : 'not taken'));
            $withKey += $sent ? 1 : 0;
        }
        $this->assertSame(
            count(array_filter(WalletRoutes::ROUTES, static fn (array $route): bool => $route['idempotencyKey'])),
            $withKey
        );
    }

    /** Lock: every body is one the gateway DTO accepts (no unknown key, every required key, amounts as digit strings). */
    public function testEveryBodyFitsTheGatewayDto(): void
    {
        foreach ($this->runAll() as $record) {
            $route = WalletRoutes::ROUTES[$record['route']];
            $body = self::bodyOf($record['call']);
            if ($route['method'] === 'GET') {
                $this->assertNull($record['call']['body'], "{$record['route']} is sent without a body");
            }
            $allowed = array_merge($route['required'] ?? [], $route['optional'] ?? []);
            $this->assertSame([], array_values(array_diff(array_keys($body), $allowed)), "{$record['route']}: unknown body keys");
            $this->assertSame([], array_values(array_diff($route['required'] ?? [], array_keys($body))), "{$record['route']}: missing body keys");
            if (isset($body['amount'])) {
                $this->assertIsString($body['amount'], "{$record['route']}: amount is a string");
            }
            $query = (string) parse_url($record['call']['url'], PHP_URL_QUERY);
            parse_str($query, $params);
            $this->assertSame([], array_values(array_diff(array_keys($params), $route['query'] ?? [])), "{$record['route']}: unknown query keys");
        }
    }

    // --- Local validation ----------------------------------------------------------------

    /** @return array<string,array{0:callable(WalletClient):mixed}> */
    public static function invalidCalls(): array
    {
        return [
            'unknown body field' => [static fn (WalletClient $w) => $w->endUsers->create(['programId' => self::PROGRAM, 'accountType' => 'PERSONAL', 'phone' => '+905350000000', 'email' => 'a@example.com'], 'eu-1')],
            'businessAccountId on a payment request' => [static fn (WalletClient $w) => $w->payments->request(['amount' => '100', 'currency' => 'TRY', 'paymentPurpose' => 'ORDER', 'businessAccountId' => self::BUSINESS], 'pay-1')],
            'missing required field' => [static fn (WalletClient $w) => $w->transfers->initiateW2W(self::w2wQuote(['amount' => null]), 'w2w-1')],
            'empty required field' => [static fn (WalletClient $w) => $w->transfers->quoteW2W(self::w2wQuote(['endUserId' => '']))],
            'missing buyerEndUserId' => [static fn (WalletClient $w) => $w->payments->approve(self::OPERATION, ['integrityHash' => 'ih', 'nonce' => 'n'], 'pay-1-approve')],
            'float amount' => [static fn (WalletClient $w) => $w->transfers->quoteW2W(self::w2wQuote(['amount' => 125.5]))],
            'whole float amount' => [static fn (WalletClient $w) => $w->transfers->quoteW2W(self::w2wQuote(['amount' => 100.0]))],
            'decimal string amount' => [static fn (WalletClient $w) => $w->transfers->quoteW2W(self::w2wQuote(['amount' => '125.50']))],
            'negative amount' => [static fn (WalletClient $w) => $w->transfers->quoteW2W(self::w2wQuote(['amount' => -1]))],
            '19-digit amount' => [static fn (WalletClient $w) => $w->transfers->quoteW2W(self::w2wQuote(['amount' => '1234567890123456789']))],
            'boolean amount' => [static fn (WalletClient $w) => $w->transfers->quoteW2W(self::w2wQuote(['amount' => true]))],
            'float amount in a bulk item' => [static fn (WalletClient $w) => $w->bulkPayouts->create(['programId' => self::PROGRAM, 'sourceWalletAccountId' => self::ACCOUNT, 'currency' => 'TRY', 'items' => [['targetType' => 'WALLET', 'targetRef' => '1', 'amount' => 10.5]]], 'bulk-1')],
            'unknown bulk item field' => [static fn (WalletClient $w) => $w->bulkPayouts->create(['programId' => self::PROGRAM, 'sourceWalletAccountId' => self::ACCOUNT, 'currency' => 'TRY', 'items' => [['targetType' => 'WALLET', 'targetRef' => '1', 'amount' => '10', 'note' => 'x']]], 'bulk-1')],
            'no bulk items' => [static fn (WalletClient $w) => $w->bulkPayouts->create(['programId' => self::PROGRAM, 'sourceWalletAccountId' => self::ACCOUNT, 'currency' => 'TRY', 'items' => []], 'bulk-1')],
            'employee row without phone' => [static fn (WalletClient $w) => $w->corporate->importEmployees(self::CORPORATE, [['displayName' => 'Can']], 'corp-1')],
            'employee rows as a map' => [static fn (WalletClient $w) => $w->corporate->importEmployees(self::CORPORATE, ['a' => ['phone' => '+90535']], 'corp-1')],
            'empty Idempotency-Key' => [static fn (WalletClient $w) => $w->transfers->initiateW2W(self::w2wQuote(), '')],
            'blank Idempotency-Key' => [static fn (WalletClient $w) => $w->transfers->initiateW2W(self::w2wQuote(), "  \t ")],
            'oversized Idempotency-Key' => [static fn (WalletClient $w) => $w->transfers->initiateW2W(self::w2wQuote(), str_repeat('k', 129))],
            'Idempotency-Key with a control character' => [static fn (WalletClient $w) => $w->transfers->initiateW2W(self::w2wQuote(), "w2w\n1")],
            'blank path id' => [static fn (WalletClient $w) => $w->endUsers->get('  ')],
            'blank operation id' => [static fn (WalletClient $w) => $w->transfers->completeW2W('', self::completion(), 'w2w-1-complete')],
            'unknown filter' => [static fn (WalletClient $w) => $w->operations->list(['endUser' => self::END_USER])],
            'array filter' => [static fn (WalletClient $w) => $w->endUsers->list(['status' => ['ACTIVE', 'FROZEN']])],
            'cursor on bulk payouts' => [static fn (WalletClient $w) => $w->bulkPayouts->list(['cursor' => 'c2'])],
            'unknown QR image format' => [static fn (WalletClient $w) => $w->qr->image(self::QR, ['format' => 'jpg'])],
            'QR image scale 0' => [static fn (WalletClient $w) => $w->qr->image(self::QR, ['scale' => 0])],
            'QR image scale 21' => [static fn (WalletClient $w) => $w->qr->image(self::QR, ['scale' => 21])],
            'QR image scale as a string' => [static fn (WalletClient $w) => $w->qr->image(self::QR, ['scale' => '4'])],
            'unknown QR image option' => [static fn (WalletClient $w) => $w->qr->image(self::QR, ['size' => 512])],
            'non-boolean approve' => [static fn (WalletClient $w) => $w->bulkPayouts->approve(self::BATCH, ['approve' => 'yes'])],
            'unknown option on freeze' => [static fn (WalletClient $w) => $w->endUsers->freeze(self::END_USER, ['note' => 'x'], 'eu-freeze-1')],
        ];
    }

    /** Lock: an invalid call is a ConfigurationException and nothing is sent. */
    #[DataProvider('invalidCalls')]
    public function testInvalidCallsAreRefusedBeforeAnythingIsSent(callable $invalid): void
    {
        [$wallet, $http] = $this->make();
        try {
            $invalid($wallet);
            $this->fail('expected a ConfigurationException');
        } catch (ConfigurationException $expected) {
            $this->assertSame(0, $http->callCount(), 'nothing was sent');
        }
    }

    public function testTheInvokerRefusesWhatNoPublicMethodCanSend(): void
    {
        [, $http, $api] = $this->make();
        $invoker = new WalletInvoker($api);
        $refused = [
            'an Idempotency-Key on a route that takes none' => static fn () => $invoker->call('quoteW2W', [], self::w2wQuote(), 'quote-1'),
            'an unknown route' => static fn () => $invoker->call('deleteEndUser'),
            'a body on a route without fields' => static fn () => $invoker->call('submitBulkPayout', ['batchId' => self::BATCH], ['force' => true]),
            'a JSON read of the image route' => static fn () => $invoker->call('getQrImage', ['qrId' => self::QR]),
            'a binary read of a JSON route' => static fn () => $invoker->callBinary('getQr', ['qrId' => self::QR]),
            'a missing path parameter' => static fn () => $invoker->call('getEndUser'),
        ];
        foreach ($refused as $case => $send) {
            try {
                $send();
                $this->fail("expected a ConfigurationException for {$case}");
            } catch (ConfigurationException $expected) {
                $this->assertSame(0, $http->callCount(), "{$case}: nothing was sent");
            }
        }
    }

    public function testIntegerAmountsAreSentAsMinorUnitStrings(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope(['operationId' => self::OPERATION]));
        $http->pushJson(201, self::envelope(['batchId' => self::BATCH]));

        $wallet->transfers->quoteW2W(self::w2wQuote(['amount' => 12550]));
        $wallet->bulkPayouts->create(['programId' => self::PROGRAM, 'sourceWalletAccountId' => self::ACCOUNT, 'currency' => 'TRY', 'items' => [['targetType' => 'WALLET', 'targetRef' => '5001234567', 'amount' => 0]]], 'bulk-zero');

        $this->assertSame('12550', self::bodyOf($http->calls[0])['amount']);
        $this->assertStringContainsString('"amount":"12550"', (string) $http->calls[0]['body']);
        $this->assertSame('0', self::bodyOf($http->calls[1])['items'][0]['amount']);
    }

    public function testTheIdempotencyKeyIsTrimmedAndMayBe128Characters(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(201, self::envelope([]));
        $http->pushJson(201, self::envelope([]));

        $wallet->transfers->initiateW2W(self::w2wQuote(), '  w2w-order-1042  ');
        $wallet->transfers->initiateW2W(self::w2wQuote(), str_repeat('k', 128));

        $this->assertSame('w2w-order-1042', $http->calls[0]['headers']['Idempotency-Key']);
        $this->assertSame(str_repeat('k', 128), $http->calls[1]['headers']['Idempotency-Key']);
    }

    // --- Two-step money movement ---------------------------------------------------------

    public function testCompleteW2WPutsTheOperationIdInTheBody(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope(['operationId' => self::OPERATION, 'status' => 'COMPLETED']));

        $result = $wallet->transfers->completeW2W(self::OPERATION, self::completion(), 'w2w-order-1042-complete');

        $call = $http->lastCall();
        $this->assertSame('COMPLETED', $result['status']);
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE_URL . '/api/v1/wallet/transfers/w2w/' . self::OPERATION . '/complete', $call['url']);
        $this->assertSame(
            ['endUserId' => self::END_USER, 'integrityHash' => 'ih_1', 'nonce' => 'n_1', 'otpCode' => '123456', 'operationId' => self::OPERATION],
            self::bodyOf($call)
        );
        $this->assertSame('w2w-order-1042-complete', $call['headers']['Idempotency-Key']);
        $this->assertSame('OK', $this->signatureGuardVerdict($call));
    }

    public function testEveryCompleteStepRefusesAMismatchingOperationId(): void
    {
        [$wallet, $http] = $this->make();
        $request = self::completion() + ['operationId' => '10000000-0000-4000-8000-0000000000ff'];
        $completes = [
            'completeW2W' => static fn () => $wallet->transfers->completeW2W(self::OPERATION, $request, 'c-1'),
            'completeW2Iban' => static fn () => $wallet->transfers->completeW2Iban(self::OPERATION, $request, 'c-2'),
            'completeBankWithdrawal' => static fn () => $wallet->withdrawals->complete(self::OPERATION, $request, 'c-3'),
        ];
        foreach ($completes as $name => $complete) {
            try {
                $complete();
                $this->fail("{$name} must refuse a body operationId that differs from the path");
            } catch (ConfigurationException $expected) {
                $this->assertSame(0, $http->callCount(), "{$name}: nothing was sent");
            }
        }

        $http->pushJson(200, self::envelope([]));
        $wallet->withdrawals->complete(self::OPERATION, self::completion() + ['operationId' => self::OPERATION], 'c-4');
        $this->assertSame(self::OPERATION, self::bodyOf($http->lastCall())['operationId'], 'a matching operationId is sent');
    }

    public function testAnEmptyOptionalBodyIsSentWithoutABody(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope(['status' => 'FROZEN']));
        $http->pushJson(200, self::envelope(['collectionEndUserId' => null]));

        $wallet->endUsers->freeze(self::END_USER, [], 'eu-freeze-2');
        $wallet->programs->setCollectionAnchor(self::PROGRAM, ['reason' => null]);

        foreach ($http->calls as $call) {
            $this->assertNull($call['body']);
            $this->assertArrayNotHasKey('Content-Type', $call['headers']);
            $this->assertSame('OK', $this->signatureGuardVerdict($call));
        }
    }

    public function testBulkApproveDefaultsToApproveAndCanDecline(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope([]));
        $http->pushJson(200, self::envelope([]));

        $wallet->bulkPayouts->approve(self::BATCH);
        $wallet->bulkPayouts->approve(self::BATCH, ['reason' => 'over budget', 'approve' => false]);

        $this->assertSame('{"approve":true}', $http->calls[0]['body']);
        $this->assertSame('{"approve":false,"reason":"over budget"}', $http->calls[1]['body']);
    }

    public function testQrMetadataIsAlwaysAnObjectAndIntegerKeysKeepTheSignatureValid(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(201, self::envelope([]));
        $http->pushJson(201, self::envelope([]));

        $wallet->qr->create(['qrType' => WalletQrType::P2P_STATIC, 'currency' => 'TRY', 'metadata' => []], 'qr-1');
        $wallet->qr->create(['qrType' => WalletQrType::P2P_STATIC, 'currency' => 'TRY', 'metadata' => ['shelf' => 'A', '2024' => 'y']], 'qr-2');

        $this->assertStringContainsString('"metadata":{}', (string) $http->calls[0]['body']);
        $this->assertStringContainsString('"metadata":{"2024":"y","shelf":"A"}', (string) $http->calls[1]['body']);
        $this->assertSame('OK', $this->signatureGuardVerdict($http->calls[1]));
    }

    public function testPathIdsAreTrimmedAndEncoded(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope([]));

        $wallet->endUsers->getByExternalId(' crm/42 ', self::PROGRAM);

        $call = $http->lastCall();
        $this->assertSame(self::BASE_URL . '/api/v1/wallet/end-users/by-external/crm%2F42?programId=' . self::PROGRAM, $call['url']);
        $this->assertSame('OK', $this->signatureGuardVerdict($call));
    }

    // --- Lists ---------------------------------------------------------------------------

    public function testAListReadsItemsAndNextCursor(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope(['items' => [['id' => 'eu_1'], ['id' => 'eu_2']], 'nextCursor' => 'cur/2+=']));

        $page = $wallet->endUsers->list(['limit' => 2, 'programId' => self::PROGRAM, 'status' => null]);

        $this->assertSame(['eu_1', 'eu_2'], array_column($page->items(), 'id'));
        $this->assertSame(2, $page->count());
        $this->assertSame('cur/2+=', $page->nextCursor());
        $this->assertTrue($page->hasMore());
        $this->assertSame('cur/2+=', $page->raw()['nextCursor']);
        $this->assertSame(
            self::BASE_URL . '/api/v1/wallet/end-users?programId=' . self::PROGRAM . '&limit=2',
            $http->lastCall()['url'],
            'filters go out in the route order, without empty values'
        );
        $this->assertSame('OK', $this->signatureGuardVerdict($http->lastCall()));
    }

    public function testOperationsReadTheOperationsCollection(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope(['operations' => [['id' => 'op_1']], 'nextCursor' => null]));

        $page = $wallet->operations->list(['endUserId' => self::END_USER]);

        $this->assertSame([['id' => 'op_1']], $page->items());
        $this->assertNull($page->nextCursor());
        $this->assertFalse($page->hasMore());
    }

    public function testAPageInfoBesideDataIsHonoured(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope(
            ['operations' => [['id' => 'op_1']], 'nextCursor' => null],
            ['pageInfo' => ['hasMore' => true, 'nextCursor' => 'c-top']]
        ));

        $page = $wallet->operations->list();

        $this->assertSame('c-top', $page->nextCursor());
        $this->assertTrue($page->hasMore());
    }

    public function testIterateFollowsTheCursorsUntilTheLastPage(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(200, self::envelope(['operations' => [['id' => 'op_1'], ['id' => 'op_2']], 'nextCursor' => 'c2']));
        $http->pushJson(200, self::envelope(['operations' => [['id' => 'op_3']], 'nextCursor' => null]));

        $ids = array_column(iterator_to_array($wallet->operations->iterate(['limit' => 2]), false), 'id');

        $this->assertSame(['op_1', 'op_2', 'op_3'], $ids);
        $this->assertSame(2, $http->callCount());
        $this->assertSame(self::BASE_URL . '/api/v1/wallet/operations?limit=2&cursor=c2', $http->calls[1]['url']);
        $this->assertSame('OK', $this->signatureGuardVerdict($http->calls[1]));
    }

    // --- QR image ------------------------------------------------------------------------

    public function testQrImageReturnsTheBytes(): void
    {
        [$wallet, $http] = $this->make();
        $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0d";
        $http->push(new HttpResponse(200, ['content-type' => 'image/png'], $png));

        $image = $wallet->qr->image(self::QR, ['format' => WalletQrImageFormat::PNG, 'scale' => 8]);

        $this->assertInstanceOf(WalletQrImage::class, $image);
        $this->assertSame($png, $image->bytes());
        $this->assertSame('image/png', $image->contentType());
        $this->assertSame('png', $image->extension());

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame(self::BASE_URL . '/api/v1/wallet/qr/' . self::QR . '/image?format=png&scale=8', $call['url']);
        $this->assertStringContainsString('image/png', $call['headers']['Accept']);
        $this->assertSame('OK', $this->signatureGuardVerdict($call));
    }

    public function testAQrImageErrorIsReadAsAJsonError(): void
    {
        [$wallet, $http] = $this->make();
        $http->pushJson(404, ['status' => 'error', 'code' => 'WALLET_NOT_FOUND', 'message' => 'QR code not found']);

        try {
            $wallet->qr->image(self::QR);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('WALLET_NOT_FOUND', $e->getErrorCode());
        }
    }

    // --- Facade and constants ------------------------------------------------------------

    public function testTheFacadeExposesTheWalletClient(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $lunixi = LunixiClient::create(['baseUrl' => self::BASE_URL, 'keyId' => 'kid_1', 'privateKey' => $keys['privateKey']], new FakeHttpClient());

        $this->assertInstanceOf(WalletClient::class, $lunixi->wallet());
        $this->assertSame($lunixi->wallet(), $lunixi->wallet());
        $this->assertInstanceOf(WalletTransfers::class, $lunixi->wallet()->transfers);
    }

    public function testEnumClassesListEveryConstantInAll(): void
    {
        $classes = [
            WalletAccountType::class, WalletKycLevel::class, WalletOtpChannel::class, WalletQrType::class,
            WalletQrImageFormat::class, WalletBulkTargetType::class,
            WalletEndUserStatus::class, WalletCorporateAccountStatus::class,
        ];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $constants = $reflection->getConstants();
            $all = $constants['ALL'];
            unset($constants['ALL']);
            $this->assertSame(array_values($constants), $all, "{$class}::ALL");
            $this->assertTrue($reflection->getConstructor() !== null && $reflection->getConstructor()->isPrivate(), "{$class} is not instantiable");
        }
    }
}
