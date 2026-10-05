<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use DateTimeImmutable;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\HttpResponse;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Payment\CreatePaymentLinkRequest;
use Lunixi\Sdk\Payment\PaymentLink;
use Lunixi\Sdk\Payment\PaymentLinkAttemptState;
use Lunixi\Sdk\Payment\PaymentLinkClient;
use Lunixi\Sdk\Payment\PaymentLinkCustomField;
use Lunixi\Sdk\Payment\PaymentLinkItem;
use Lunixi\Sdk\Payment\PaymentLinkState;
use Lunixi\Sdk\Payment\PaymentLinkUsage;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Tests\Support\SignatureGuardStandIn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PaymentLinkClientTest extends TestCase
{
    private const BASE_URL = 'https://gw.example.com';
    private const LINK_ID = '3f1c2a9e-5b7d-4c1e-9a2b-6d8e0f1a2b3c';
    private const ASSET_ID = '33333333-3333-4333-8333-333333333333';

    /**
     * A presigned PUT URL: the storage host, never the gateway. Its query string is the signature.
     * `.invalid` (RFC 2606) never resolves, so a request that escaped the fake transport sends nothing.
     */
    private const STORAGE_URL = 'https://storage.invalid/lunixi-media/org_1/general/' . self::ASSET_ID . '.png'
        . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=900&X-Amz-SignedHeaders=content-type%3Bhost&X-Amz-Signature=abc123';

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0d";

    /** Headers that authenticate a gateway request. Not one of them may reach the storage host. */
    private const GATEWAY_CREDENTIAL_HEADERS = ['authorization', 'x-key-id', 'x-date', 'x-nonce', 'x-signature', 'digest'];

    /** Raw 32-byte Ed25519 public key of the client under test. */
    private string $publicKey = '';

    /** @return array{0:PaymentLinkClient,1:FakeHttpClient} */
    private function make(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $keys['publicKey']), true);
        $this->publicKey = substr($der, -32);

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
        $tokens = new TokenManager($config, $signer, $http, $store);

        // One recorder for both transports: gateway calls (ApiClient) and the image PUT to storage.
        return [new PaymentLinkClient(new ApiClient($config, $signer, $tokens, $http), $http, 5.0), $http];
    }

    /**
     * @param array{headers:array<string,string>} $call
     * @return string[] Gateway credential headers the call carries.
     */
    private static function credentialHeaders(array $call): array
    {
        return array_values(array_filter(
            array_keys($call['headers']),
            static fn (string $name): bool => in_array(strtolower($name), self::GATEWAY_CREDENTIAL_HEADERS, true)
        ));
    }

    /** @return array<string,mixed> */
    private static function uploadTarget(array $overrides = []): array
    {
        return array_merge([
            'assetId' => self::ASSET_ID,
            'uploadUrl' => self::STORAGE_URL,
            'uploadMethod' => 'PUT',
            'uploadHeaders' => ['Content-Type' => 'image/png'],
            'expiresAt' => '2026-09-29T12:15:00.000Z',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function publishedImage(array $overrides = []): array
    {
        return array_merge(['assetId' => self::ASSET_ID, 'url' => 'https://cdn.lunixi.com/assets/' . self::ASSET_ID, 'state' => 'PUBLISHED'], $overrides);
    }

    /** Queues the three answers of an image upload: create (201), the storage PUT, confirm. */
    private static function queueImageUpload(FakeHttpClient $http, ?HttpResponse $storage = null): void
    {
        $http->pushJson(201, self::envelope(self::uploadTarget(), ['statusCode' => 201]));
        $http->push($storage ?? new HttpResponse(200, [], ''));
        $http->pushJson(200, self::envelope(self::publishedImage()));
    }

    /** @param array{method:string,url:string,headers:array<string,string>,body:?string} $call */
    private function signatureGuardVerdict(array $call): string
    {
        return SignatureGuardStandIn::verdict($call, $this->publicKey, self::BASE_URL);
    }

    public function testTheStandInReserialisesLikeV8(): void
    {
        // Left: what plain json_encode sends. Right: Node's JSON.stringify(JSON.parse(left)).
        $measured = [
            '{"metadata":{"crmId":"A-77","2024":"yil","10":"x","2":"y"}}' => '{"metadata":{"2":"y","10":"x","2024":"yil","crmId":"A-77"}}',
            '{"m":{"b":1,"4294967295":2,"4294967294":3,"a":4,"01":5,"-1":6}}' => '{"m":{"4294967294":3,"b":1,"4294967295":2,"a":4,"01":5,"-1":6}}',
            '{"nested":{"z":{"9":1,"k":[3,2,1]},"5":true},"list":[{"b":1,"1":2}],"e":{},"l":[]}' => '{"nested":{"5":true,"z":{"9":1,"k":[3,2,1]}},"list":[{"1":2,"b":1}],"e":{},"l":[]}',
        ];
        foreach ($measured as $sent => $gateway) {
            $this->assertSame($gateway, SignatureGuardStandIn::v8Reserialize($sent));
        }
    }

    /** @return array<string,mixed> */
    private static function linkJson(array $overrides = []): array
    {
        return array_merge([
            'id' => self::LINK_ID,
            'shortCode' => 'K7M2Q9XR4T',
            'url' => 'https://pay.lunixi.com/K7M2Q9XR4T',
            'environment' => 'TEST',
            'state' => 'ACTIVE',
            'reviewState' => 'NONE',
            'version' => 1,
            'rowVersion' => 1,
            'usage' => 'SINGLE_USE',
            'amountMode' => 'FIXED',
            'currency' => 'TRY',
            'amountMinor' => 150000,
            'title' => 'Kasım danışmanlık bedeli',
            'reference' => 'INV-2026-0042',
            'metadata' => ['crmId' => 'A-77'],
            'counters' => ['paidCount' => 2, 'collectedAmountMinor' => 300000],
            'availability' => ['soldOut' => false, 'remainingQuantity' => null, 'inProgress' => false, 'items' => []],
            'amountLocked' => true,
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function envelope(array $data, array $extra = []): array
    {
        return array_merge(['status' => 'success', 'code' => 'SUCCESS', 'data' => $data, 'statusCode' => 200, 'requestId' => 'req_1'], $extra);
    }

    private static function page(array $items, bool $hasMore = false, ?string $cursor = null): array
    {
        return self::envelope(['items' => $items], ['pageInfo' => ['hasMore' => $hasMore, 'nextCursor' => $cursor, 'totalCount' => null]]);
    }

    public function testCreateIsSignedPerRequestAndVerifiesLikeSignatureGuard(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::linkJson(['replayed' => false])));

        $request = CreatePaymentLinkRequest::fixed(PaymentLinkUsage::SINGLE_USE, 150000, 'try', 'Kasım danışmanlık bedeli')
            ->withDescription("Satır sonu\n ve \"tırnak\" / eğik çizgi")
            ->withExpiresAt(new DateTimeImmutable('2026-10-31T23:59:59+03:00'))
            ->withReference('INV-2026-0042')
            ->withRecipient(['name' => 'Ayşe Yılmaz', 'email' => 'ayse@example.com'])
            ->withMetadata(['crmId' => 'A-77']);

        $link = $links->create($request, 'plink-create-INV-2026-0042');

        $this->assertSame(self::LINK_ID, $link->id());
        $this->assertFalse($link->wasReplayed());
        $this->assertSame(1, $http->callCount(), 'the seeded token is reused: no mint call');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links', $call['url']);
        $this->assertSame('plink-create-INV-2026-0042', $call['headers']['Idempotency-Key']);
        $this->assertSame('mk_test_sk_kid_1', $call['headers']['X-Key-Id']);
        $this->assertSame('OK', $this->signatureGuardVerdict($call));

        $body = json_decode((string) $call['body'], true);
        $this->assertSame('TRY', $body['currency']);
        $this->assertSame(150000, $body['amountMinor']);
        $this->assertSame('2026-10-31T20:59:59.000Z', $body['expiresAt'], 'times go out as ISO-8601 UTC');
        $this->assertArrayNotHasKey('environment', $body, 'the key decides the environment');
    }

    /**
     * Lock: every public method sends a signed request. The method list is
     * read from the class, so a method added later without an entry here fails.
     */
    public function testEveryPublicMethodSendsASignedRequest(): void
    {
        [$links, $http] = $this->make();
        $invocations = [
            'create' => static fn () => $links->create(CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x'), 'plink-lock-create'),
            'list' => static fn () => $links->list(['limit' => 5]),
            'iterate' => static fn () => iterator_to_array($links->iterate()),
            'get' => static fn () => $links->get(self::LINK_ID),
            'update' => static fn () => $links->update(self::LINK_ID, 1, ['title' => 'y']),
            'pause' => static fn () => $links->pause(self::LINK_ID),
            'resume' => static fn () => $links->resume(self::LINK_ID, 2),
            'deactivate' => static fn () => $links->deactivate(self::LINK_ID, null, 'closed'),
            'action' => static fn () => $links->action(self::LINK_ID, 'pause'),
            'send' => static fn () => $links->send(self::LINK_ID, PaymentLinkClient::CHANNEL_EMAIL, 'plink-lock-send'),
            'qr' => static fn () => $links->qr(self::LINK_ID),
            'listAttempts' => static fn () => $links->listAttempts(self::LINK_ID),
            'iterateAttempts' => static fn () => iterator_to_array($links->iterateAttempts(self::LINK_ID)),
            'listPayments' => static fn () => $links->listPayments(self::LINK_ID),
            'iteratePayments' => static fn () => iterator_to_array($links->iteratePayments(self::LINK_ID)),
            'createImageUpload' => static fn () => $links->createImageUpload('cover.png', PaymentLinkClient::IMAGE_PNG, 4),
            'confirmImageUpload' => static fn () => $links->confirmImageUpload(self::ASSET_ID),
            'uploadImage' => static fn () => $links->uploadImage('cover.png', PaymentLinkClient::IMAGE_PNG, self::PNG),
        ];

        $public = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new ReflectionClass(PaymentLinkClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m): bool => !$m->isConstructor() && !$m->isStatic()
            )
        );
        $expected = array_keys($invocations);
        sort($public);
        sort($expected);
        $this->assertSame($expected, $public, 'every public PaymentLinkClient method must be covered by this lock');

        foreach ($invocations as $name => $invoke) {
            $before = $http->callCount();
            if ($name === 'uploadImage') {
                self::queueImageUpload($http);
            } elseif ($name === 'createImageUpload') {
                $http->pushJson(201, self::envelope(self::uploadTarget()));
            } elseif ($name === 'confirmImageUpload') {
                $http->pushJson(200, self::envelope(self::publishedImage()));
            } else {
                // One response fits every other call: list methods read `data.items`, the rest read `data`.
                $http->push($name === 'qr'
                    ? new HttpResponse(200, ['content-type' => 'image/svg+xml'], '<svg/>')
                    : new HttpResponse(200, [], (string) json_encode(self::page([self::linkJson()]))));
            }
            $invoke();
            $this->assertGreaterThan($before, $http->callCount(), "{$name} sent a request");
            foreach (array_slice($http->calls, $before) as $call) {
                if (strpos($call['url'], self::BASE_URL . '/') === 0) {
                    $this->assertSame('OK', $this->signatureGuardVerdict($call), "{$name}: {$call['method']} {$call['url']} must carry a valid API-key signature");
                    continue;
                }
                // Only the image PUT leaves the gateway, and it must leave without the key's credentials.
                $this->assertSame('uploadImage', $name, "{$name} must not call a host other than the gateway ({$call['url']})");
                $this->assertSame([], self::credentialHeaders($call), "{$name}: the storage PUT must not carry gateway credentials");
            }
        }
    }

    public function testTheSignatureGuardStandInRejectsWhatTheGatewayRejects(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::linkJson()));
        $links->create(CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x'), 'plink-negative-1');
        $call = $http->lastCall();
        $this->assertSame('OK', $this->signatureGuardVerdict($call));

        $indented = $call;
        $indented['body'] = (string) json_encode(json_decode((string) $call['body']), JSON_PRETTY_PRINT);
        $this->assertSame('OK', $this->signatureGuardVerdict($indented), 'the Digest covers the parsed JSON, not the bytes');
        $indented['headers']['Digest'] = 'SHA-256=' . base64_encode(hash('sha256', $indented['body'], true));
        $this->assertSame('INVALID_DIGEST', $this->signatureGuardVerdict($indented));

        $retargeted = $call;
        $retargeted['url'] .= '?limit=1';
        $this->assertSame('INVALID_SIGNATURE', $this->signatureGuardVerdict($retargeted));

        $bearerOnly = $call;
        $bearerOnly['headers'] = ['Authorization' => $call['headers']['Authorization'], 'Content-Type' => 'application/json'];
        $this->assertSame('AUTH_HEADERS_MISSING', $this->signatureGuardVerdict($bearerOnly));

        $tampered = $call;
        $tampered['body'] = str_replace('"amountMinor":1000', '"amountMinor":1', (string) $call['body']);
        $this->assertSame('INVALID_DIGEST', $this->signatureGuardVerdict($tampered));
    }

    public function testLineTerminatorsInTextAreSentRawLikeJsonStringify(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::linkJson()));

        $text = "Birinci satır\u{2028}ikinci paragraf\u{2029}son";
        $links->create(CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withDescription($text), 'plink-u2028-1');

        $body = (string) $http->lastCall()['body'];
        // JSON.stringify leaves U+2028/U+2029 unescaped; the gateway hashes that form.
        $this->assertStringContainsString($text, $body);
        $this->assertStringNotContainsString('\\u2028', $body, 'not escaped as \\u2028');
        $this->assertSame('OK', $this->signatureGuardVerdict($http->lastCall()));
    }

    /**
     * Metadata keys are yours; "2024" is as valid as "crmId". The gateway
     * re-serialises the parsed body with integer-like keys first, so the SDK
     * must send them in that order or the Digest does not match (401
     * INVALID_DIGEST). The expected body is Node's output for this request.
     */
    public function testIntegerLikeMetadataKeysKeepTheSignatureValid(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::linkJson()));

        $links->create(
            CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withMetadata(['crmId' => 'A-77', '2024' => 'yil']),
            'plink-metadata-order-1'
        );

        $call = $http->lastCall();
        $this->assertSame(
            '{"usage":"SINGLE_USE","amountMode":"FIXED","currency":"TRY","title":"x","amountMinor":1000,"metadata":{"2024":"yil","crmId":"A-77"}}',
            $call['body']
        );
        $this->assertSame('OK', $this->signatureGuardVerdict($call));
    }

    public function testMetadataWithTheSingleKeyZeroIsStillAnObject(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::linkJson()));
        $http->pushJson(200, self::envelope(self::linkJson()));

        $links->create(CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withMetadata(['0' => 'first']), 'plink-metadata-zero-1');
        $this->assertStringEndsWith('"metadata":{"0":"first"}}', (string) $http->calls[0]['body'], 'PHP would encode it as ["first"]');

        $links->update(self::LINK_ID, 1, ['recipient' => ['email' => 'a@example.com'], 'metadata' => ['0' => 'first']]);
        $this->assertSame('{"expectedRowVersion":1,"recipient":{"email":"a@example.com"},"metadata":{"0":"first"}}', $http->calls[1]['body']);
        $this->assertSame('OK', $this->signatureGuardVerdict($http->calls[1]));
    }

    public function testCreateRequiresAWellFormedIdempotencyKeyBeforeSending(): void
    {
        [$links, $http] = $this->make();
        $request = CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x');

        foreach (['', '   ', 'short', 'has a space', str_repeat('k', 201)] as $key) {
            try {
                $links->create($request, $key);
                $this->fail("key '{$key}' must be refused");
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testReplayedCreateIsVisible(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::linkJson(['replayed' => true])));

        $link = $links->create(CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x'), 'plink-replay-1');

        $this->assertTrue($link->wasReplayed());
    }

    public function testBuildersProduceTheContractBody(): void
    {
        $open = CreatePaymentLinkRequest::open(PaymentLinkUsage::MULTI_USE, 'TRY', 'Bağış', 1000, 500000, [5000, 10000, 25000])
            ->withButtonLabelKey(CreatePaymentLinkRequest::BUTTON_DONATE)
            ->toArray();
        $this->assertSame([
            'usage' => 'MULTI_USE',
            'amountMode' => 'OPEN',
            'currency' => 'TRY',
            'title' => 'Bağış',
            'minAmountMinor' => 1000,
            'maxAmountMinor' => 500000,
            'suggestedAmountsMinor' => [5000, 10000, 25000],
            'buttonLabelKey' => 'DONATE',
        ], $open);

        $itemized = CreatePaymentLinkRequest::itemized(PaymentLinkUsage::MULTI_USE, 'TRY', 'Etkinlik', [
            (new PaymentLinkItem('Standart bilet', 45000))->withItemKey('standard')->withQuantityRange(0, 5)->withCapacity(100),
        ])
            ->addCustomField((new PaymentLinkCustomField('invoice_no', 'Fatura no', PaymentLinkCustomField::TYPE_TEXT))->withRequired()->withMaxLength(32))
            ->withCapacity(null)
            ->toArray();
        $this->assertSame([[
            'name' => 'Standart bilet', 'unitPriceMinor' => 45000, 'itemKey' => 'standard', 'minQuantity' => 0, 'maxQuantity' => 5, 'capacity' => 100,
        ]], $itemized['items']);
        $this->assertSame([[
            'key' => 'invoice_no', 'label' => 'Fatura no', 'fieldType' => 'TEXT', 'required' => true, 'maxLength' => 32,
        ]], $itemized['customFields']);
        $this->assertNull($itemized['capacity']);
    }

    public function testEveryBuilderOutputKeyIsAContractField(): void
    {
        $full = CreatePaymentLinkRequest::fixed('MULTI_USE', 1000, 'TRY', 'Tam')
            ->withEnvironment(CreatePaymentLinkRequest::ENVIRONMENT_TEST)
            ->withQuantity(1, 5)
            ->withCapacity(100)
            ->withDescription('d')
            ->withImageAssetId('0b6f2c1e-9a4d-4e7b-8c3a-5d2f1e0a9b8c')
            ->withSuccessMessage('ok')
            ->withSuccessRedirectUrl('https://merchant.example/done')
            ->withTaxNote('KDV dahil')
            ->withLocale('tr')
            ->withStartsAt(new DateTimeImmutable('2026-10-01T00:00:00Z'))
            ->withExpiresAt('2026-10-31T20:59:59.000Z')
            ->withEventAt(null)
            ->withButtonLabelKey(CreatePaymentLinkRequest::BUTTON_BUY)
            ->withTermsAcceptanceRequired()
            ->withBuyerFields(['name' => 'REQUIRED', 'email' => 'REQUIRED', 'phone' => 'OPTIONAL', 'address' => 'HIDDEN', 'identityNumber' => 'HIDDEN'])
            ->withCustomFields([(new PaymentLinkCustomField('color', 'Renk', PaymentLinkCustomField::TYPE_SELECT))->withOptions(['Mavi', 'Kırmızı'])->withHelpText('h')])
            ->withRecipient(['email' => 'a@example.com'])
            ->withPrefill(['name' => 'Ayşe', 'country' => 'TR'])
            ->withVerification(true)
            ->withReminders(48, 2)
            ->withCheckout(true, ['card'])
            ->withReference('INV-1')
            ->withTags(['a'])
            ->withBranchCode('b')
            ->withSalesChannel('s')
            ->withCampaign('c')
            ->withAgentCode('ag')
            ->withMerchantNotifyEmails(['finans@merchant.example'])
            ->withMetadata(['k' => 'v'])
            ->toArray();

        $allowed = array_merge(['environment'], CreatePaymentLinkRequest::FIELDS);
        $this->assertSame([], array_values(array_diff(array_keys($full), $allowed)), 'the gateway runs forbidNonWhitelisted: an unknown key is a 400');
        $this->assertSame(['required' => true, 'channel' => 'EMAIL'], $full['verification']);
        $this->assertSame(['enabled' => true, 'intervalHours' => 48, 'maxCount' => 2], $full['reminders']);
        $this->assertSame('2026-10-01T00:00:00.000Z', $full['startsAt']);
    }

    /** @return array<string,array{0:callable}> */
    public static function invalidBuilders(): array
    {
        return [
            'zero amount' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 0, 'TRY', 'x')],
            'unknown usage' => [static fn () => CreatePaymentLinkRequest::fixed('ONCE', 1000, 'TRY', 'x')],
            'unknown amount mode' => [static fn () => new CreatePaymentLinkRequest('SINGLE_USE', 'FREE', 'TRY', 'x')],
            'unsupported currency' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'XYZ', 'x')],
            'empty title' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', ' ')],
            'no items' => [static fn () => CreatePaymentLinkRequest::itemized('MULTI_USE', 'TRY', 'x', [])],
            'file field' => [static fn () => new PaymentLinkCustomField('k', 'l', 'FILE')],
            'numeric metadata' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withMetadata(['a' => 1])],
            'unknown environment' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withEnvironment('PROD')],
            'unknown buyer field' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withBuyerFields(['fax' => 'REQUIRED'])],
            'unknown recipient key' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withRecipient(['tckn' => '1'])],
            'unparseable time' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withExpiresAt(42)],
            'unknown locale' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withLocale('de')],
            'asset id that is not a uuid' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x')->withImageAssetId('logo.png')],
            'item image that is a url' => [static fn () => (new PaymentLinkItem('Bilet', 1000))->withImageAssetId('https://cdn.example/x.png')],
            'amount beyond a JSON-safe integer' => [static fn () => CreatePaymentLinkRequest::fixed('SINGLE_USE', 9007199254740992, 'TRY', 'x')],
            'item price beyond a JSON-safe integer' => [static fn () => new PaymentLinkItem('Bilet', 9007199254740992)],
        ];
    }

    #[DataProvider('invalidBuilders')]
    public function testBuildersRejectInvalidValuesLocally(callable $build): void
    {
        $this->expectException(ConfigurationException::class);
        $build();
    }

    public function testListSendsSignedFiltersAndReadsTopLevelPageInfo(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(200, self::envelope(
            ['items' => [self::linkJson()], 'pageInfo' => ['hasMore' => false, 'nextCursor' => '']],
            ['pageInfo' => ['hasMore' => true, 'nextCursor' => 'cur/2+=', 'totalCount' => 41]]
        ));

        $page = $links->list([
            'limit' => 10,
            'state' => [PaymentLinkState::ACTIVE, PaymentLinkState::PAUSED],
            'includeTotal' => true,
            'createdFrom' => new DateTimeImmutable('2026-09-01T03:00:00+03:00'),
            'ignored' => 'x',
        ]);

        $this->assertSame(1, $page->count());
        $this->assertInstanceOf(PaymentLink::class, $page->items()[0]);
        $this->assertSame('K7M2Q9XR4T', $page->items()[0]->shortCode());
        $this->assertTrue($page->hasMore(), 'top-level pageInfo is canonical');
        $this->assertSame('cur/2+=', $page->nextCursor());
        $this->assertSame(41, $page->totalCount());

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame(
            self::BASE_URL . '/api/v1/payments/links?limit=10&includeTotal=true&state=ACTIVE%2CPAUSED&createdFrom=2026-09-01T00%3A00%3A00.000Z',
            $call['url']
        );
        $this->assertNull($call['body']);
        $this->assertSame('OK', $this->signatureGuardVerdict($call));
    }

    public function testIterateFollowsCursorsUntilTheLastPage(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(200, self::page([self::linkJson(['id' => 'a'])], true, 'c2'));
        $http->pushJson(200, self::page([self::linkJson(['id' => 'b'])], false, null));

        $ids = [];
        foreach ($links->iterate(['limit' => 1]) as $link) {
            $ids[] = $link->id();
        }

        $this->assertSame(['a', 'b'], $ids);
        $this->assertStringContainsString('cursor=c2', $http->calls[1]['url']);
    }

    public function testUpdateSendsOnlyTheChangedKeysAndNullClears(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::linkJson(['version' => 2, 'rowVersion' => 2])));

        $link = $links->update(self::LINK_ID, 1, [
            'title' => 'Yeni başlık',
            'successRedirectUrl' => null,
            'expiresAt' => new DateTimeImmutable('2026-12-31T20:59:59Z'),
            'metadata' => [],
            'items' => [(new PaymentLinkItem('Bilet', 1000))->withItemKey('std')],
        ]);

        $this->assertSame(2, $link->rowVersion());
        $call = $http->lastCall();
        $this->assertSame('PATCH', $call['method']);
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/' . self::LINK_ID, $call['url']);
        $this->assertSame(
            '{"expectedRowVersion":1,"title":"Yeni başlık","successRedirectUrl":null,"expiresAt":"2026-12-31T20:59:59.000Z","metadata":{},"items":[{"name":"Bilet","unitPriceMinor":1000,"itemKey":"std"}]}',
            $call['body'],
            'an emptied object field is {} (PHP would send [])'
        );
        $this->assertSame('OK', $this->signatureGuardVerdict($call));
    }

    public function testUpdateRefusesImmutableUnknownAndEmptyChangesLocally(): void
    {
        [$links, $http] = $this->make();
        $cases = [
            [1, ['currency' => 'USD'], "'currency' is fixed"],
            [1, ['state' => 'PAUSED'], 'Unknown payment link update field'],
            [1, [], 'at least one changed field'],
            [-1, ['title' => 'x'], 'expectedRowVersion'],
            [1, ['expiresAt' => 1790000000], 'expiresAt'],
        ];
        foreach ($cases as [$rowVersion, $changes, $message]) {
            try {
                $links->update(self::LINK_ID, $rowVersion, $changes);
                $this->fail('expected a ConfigurationException: ' . $message);
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testActionsPostToTheirRoutes(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::linkJson(['state' => 'PAUSED'])));
        $http->pushJson(200, self::envelope(self::linkJson()));
        $http->pushJson(200, self::envelope(self::linkJson(['state' => 'DEACTIVATED'])));

        $paused = $links->pause(self::LINK_ID, 3, 'Stok sayımı');
        $links->resume(self::LINK_ID, 4, null, 'plink-resume-0001');
        $deactivated = $links->deactivate(self::LINK_ID);

        $this->assertSame(PaymentLinkState::PAUSED, $paused->state());
        $this->assertSame(PaymentLinkState::DEACTIVATED, $deactivated->state());
        $this->assertSame(
            ['/pause', '/resume', '/deactivate'],
            array_map(static fn (array $c): string => substr($c['url'], strrpos($c['url'], '/')), $http->calls)
        );
        $this->assertSame('{"expectedRowVersion":3,"reason":"Stok sayımı"}', $http->calls[0]['body']);
        $this->assertSame('plink-resume-0001', $http->calls[1]['headers']['Idempotency-Key']);
        $this->assertNull($http->calls[2]['body'], 'no fields → no body; the gateway reads it as {}');

        $this->expectException(ConfigurationException::class);
        $links->action(self::LINK_ID, 'delete');
    }

    public function testSendRequiresAKeyAndAKnownChannelAndReturnsTheDelivery(): void
    {
        [$links, $http] = $this->make();
        $delivery = ['id' => 'dl_1', 'kind' => 'REQUEST', 'channel' => 'EMAIL', 'recipientMasked' => 'a***@example.com', 'state' => 'QUEUED', 'failureReason' => null];
        $http->pushJson(200, self::envelope(['delivery' => $delivery, 'replayed' => false]));

        foreach ([
            static fn () => $links->send(self::LINK_ID, 'EMAIL', ''),
            static fn () => $links->send(self::LINK_ID, 'WHATSAPP', 'plink-send-0001'),
            static fn () => $links->send(self::LINK_ID, 'EMAIL', 'plink-send-0001', ['recipient' => 'x']),
            static fn () => $links->send(self::LINK_ID, 'EMAIL', 'plink-send-0001', ['locale' => 'de']),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('expected a ConfigurationException');
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $http->callCount());

        $result = $links->send(self::LINK_ID, PaymentLinkClient::CHANNEL_EMAIL, 'plink-send-0001', ['recipientEmail' => 'ayse@example.com', 'locale' => 'tr']);

        $this->assertSame(['delivery' => $delivery, 'replayed' => false], $result);
        $call = $http->lastCall();
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/' . self::LINK_ID . '/send', $call['url']);
        $this->assertSame('{"channel":"EMAIL","recipientEmail":"ayse@example.com","locale":"tr"}', $call['body']);
        $this->assertSame('plink-send-0001', $call['headers']['Idempotency-Key']);
    }

    public function testQrReturnsTheImageBytesAndContentType(): void
    {
        [$links, $http] = $this->make();
        $png = "\x89PNG\r\n\x1a\n\x00\xff";
        $http->push(new HttpResponse(200, ['content-type' => 'image/png', 'cache-control' => 'no-store'], $png));

        $qr = $links->qr(self::LINK_ID, PaymentLinkClient::QR_PNG, 1024);

        $this->assertSame('image/png', $qr->contentType());
        $this->assertSame($png, $qr->bytes());
        $this->assertSame('png', $qr->extension());
        $call = $http->lastCall();
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/' . self::LINK_ID . '/qr?format=png&size=1024', $call['url']);
        $this->assertStringContainsString('image/png', $call['headers']['Accept']);
        $this->assertSame('OK', $this->signatureGuardVerdict($call));

        foreach ([static fn () => $links->qr(self::LINK_ID, 'gif'), static fn () => $links->qr(self::LINK_ID, 'svg', 64)] as $invalid) {
            try {
                $invalid();
                $this->fail('expected a ConfigurationException');
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAQrErrorIsReadAsAJsonError(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(404, ['status' => 'failure', 'code' => 'payment_link.link.not_found', 'message' => 'Not found']);

        try {
            $links->qr(self::LINK_ID);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('payment_link.link.not_found', $e->getErrorCode());
        }
    }

    public function testAttemptsAndPaymentsAreCursorPagesUnderTheLink(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(200, self::page([['id' => 'at_1', 'state' => 'FAILED', 'failureCode' => 'card.insufficient_funds']]));
        $http->pushJson(200, self::page([['attemptId' => 'at_2', 'paymentId' => 'pi_1', 'receiptNumber' => 'PL-20260928-K7M2Q9XR4T']]));

        $attempts = $links->listAttempts(self::LINK_ID, ['state' => [PaymentLinkAttemptState::FAILED, PaymentLinkAttemptState::EXPIRED], 'limit' => 5]);
        $payments = $links->listPayments(self::LINK_ID, ['limit' => 5, 'state' => 'ignored']);

        $this->assertSame('card.insufficient_funds', $attempts->items()[0]['failureCode']);
        $this->assertNull($attempts->nextCursor());
        $this->assertFalse($attempts->hasMore());
        $this->assertSame('pi_1', $payments->items()[0]['paymentId']);
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/' . self::LINK_ID . '/attempts?limit=5&state=FAILED%2CEXPIRED', $http->calls[0]['url']);
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/' . self::LINK_ID . '/payments?limit=5', $http->calls[1]['url']);
    }

    public function testGatewayErrorsSurfaceThePaymentLinkCodeAndDetails(): void
    {
        [$links, $http] = $this->make();
        $failure = [
            'status' => 'failure',
            'code' => 'payment_link.validation.invalid',
            'message' => 'Validation failed',
            'statusCode' => 422,
            'details' => ['errors' => [['field' => 'title', 'reason' => 'too_long']]],
        ];
        $http->pushJson(422, $failure, ['x-request-id' => 'req_9']);

        try {
            $links->create(CreatePaymentLinkRequest::fixed('SINGLE_USE', 1000, 'TRY', 'x'), 'plink-invalid-1');
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('payment_link.validation.invalid', $e->getErrorCode());
            $this->assertSame([['field' => 'title', 'reason' => 'too_long']], $e->getResponseBody()['details']['errors']);
            $this->assertSame('req_9', $e->getRequestId());
        }
        $this->assertSame(1, $http->callCount(), 'a 4xx is never retried');
    }

    public function testLinkIdsAreRequiredAndPathEncoded(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(404, ['status' => 'failure', 'code' => 'payment_link.link.not_found']);

        try {
            $links->get(' ');
            $this->fail('expected a ConfigurationException');
        } catch (ConfigurationException $expected) {
            $this->addToAssertionCount(1);
        }
        try {
            $links->get('a/b?c');
        } catch (ApiException $expected) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/a%2Fb%3Fc', $http->lastCall()['url']);
    }

    public function testReadModelExposesFullAndListShapes(): void
    {
        $full = new PaymentLink(self::linkJson());
        $this->assertSame(2, $full->paidCount());
        $this->assertSame(300000, $full->collectedAmountMinor());
        $this->assertSame(150000, $full->amountMinor());
        $this->assertSame('INV-2026-0042', $full->reference());
        $this->assertSame(['crmId' => 'A-77'], $full->metadata());
        $this->assertTrue($full->isAmountLocked());
        $this->assertTrue($full->isActive());

        $row = new PaymentLink(['id' => 'a', 'amountMode' => 'OPEN', 'amountMinor' => null, 'reference' => '', 'paidCount' => 3, 'collectedAmountMinor' => 900]);
        $this->assertSame(3, $row->paidCount());
        $this->assertSame(900, $row->collectedAmountMinor());
        $this->assertNull($row->amountMinor());
        $this->assertNull($row->reference());
        $this->assertSame([], $row->counters());
    }

    public function testFacadeExposesThePaymentLinkClient(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $lunixi = LunixiClient::create(['baseUrl' => self::BASE_URL, 'keyId' => 'kid_1', 'privateKey' => $keys['privateKey']], new FakeHttpClient());

        $this->assertInstanceOf(PaymentLinkClient::class, $lunixi->paymentLinks());
    }

    // --- Link images -------------------------------------------------------------------

    public function testCreateImageUploadDeclaresTheFileInASignedPostAndReturnsTheTarget(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(201, self::envelope(self::uploadTarget(), ['statusCode' => 201]));

        $target = $links->createImageUpload('kapak.png', PaymentLinkClient::IMAGE_PNG, 12345);

        $this->assertSame(self::uploadTarget(), $target);
        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/images', $call['url']);
        $this->assertSame('{"fileName":"kapak.png","contentType":"image/png","sizeBytes":12345}', $call['body']);
        $this->assertArrayNotHasKey('Idempotency-Key', $call['headers'], 'the route takes no Idempotency-Key');
        $this->assertSame('OK', $this->signatureGuardVerdict($call));
    }

    public function testCreateImageUploadRefusesADeclarationTheGatewayRejectsBeforeAnyRequest(): void
    {
        [$links, $http] = $this->make();
        $refused = [
            'SVG' => [static fn () => $links->createImageUpload('x.svg', 'image/svg+xml', 10), 'SVG is not accepted'],
            'GIF' => [static fn () => $links->createImageUpload('x.gif', 'image/gif', 10), 'image/png, image/jpeg, image/webp'],
            'size 0' => [static fn () => $links->createImageUpload('x.png', PaymentLinkClient::IMAGE_PNG, 0), 'positive integer'],
            'negative size' => [static fn () => $links->createImageUpload('x.png', PaymentLinkClient::IMAGE_PNG, -1), 'positive integer'],
            'blank name' => [static fn () => $links->createImageUpload(' ', PaymentLinkClient::IMAGE_PNG, 10), "'fileName' is required"],
        ];

        foreach ($refused as $label => [$invoke, $message]) {
            try {
                $invoke();
                $this->fail("{$label}: expected a ConfigurationException");
            } catch (ConfigurationException $expected) {
                $this->assertStringContainsString($message, $expected->getMessage(), $label);
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testAnUploadTargetTheSdkCannotPutToIsAnApiException(): void
    {
        $unusable = [
            'no URL' => self::uploadTarget(['uploadUrl' => null]),
            'ftp URL' => self::uploadTarget(['uploadUrl' => 'ftp://storage.invalid/object']),
            'relative URL' => self::uploadTarget(['uploadUrl' => '/relative/object']),
            'POST method' => self::uploadTarget(['uploadMethod' => 'POST']),
            'header list' => self::uploadTarget(['uploadHeaders' => ['Content-Type: image/png']]),
            'numeric header' => self::uploadTarget(['uploadHeaders' => ['Content-Length' => 12]]),
            'storage key as id' => self::uploadTarget(['assetId' => 'org_1/general/object.png']),
        ];

        foreach ($unusable as $label => $target) {
            [$links, $http] = $this->make();
            $http->pushJson(201, self::envelope($target));
            try {
                $links->createImageUpload('kapak.png', PaymentLinkClient::IMAGE_PNG, 10);
                $this->fail("{$label}: expected an ApiException");
            } catch (ApiException $e) {
                $this->assertStringContainsString('usable upload target', $e->getMessage(), $label);
                $this->assertSame($target, $e->getResponseBody()['data'], "{$label}: the answer is kept for diagnosis");
            }
        }
    }

    public function testConfirmImageUploadIsSignedWithoutABodyAndReturnsOnlyAPublishedImage(): void
    {
        [$links, $http] = $this->make();
        $http->pushJson(200, self::envelope(self::publishedImage(['extra' => 'ignored'])));

        $image = $links->confirmImageUpload(self::ASSET_ID);

        $this->assertSame(self::publishedImage(), $image);
        $call = $http->lastCall();
        $this->assertSame(self::BASE_URL . '/api/v1/payments/links/images/' . self::ASSET_ID . '/confirm', $call['url']);
        $this->assertNull($call['body']);
        $this->assertSame('OK', $this->signatureGuardVerdict($call));

        foreach (['../../links', ''] as $invalid) {
            try {
                $links->confirmImageUpload($invalid);
                $this->fail('expected a ConfigurationException');
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, $http->callCount(), 'an id that is not an asset id is never sent');

        foreach ([self::publishedImage(['state' => 'UPLOADING']), self::publishedImage(['assetId' => null]), []] as $answer) {
            [$other, $otherHttp] = $this->make();
            $otherHttp->pushJson(200, self::envelope($answer));
            try {
                $other->confirmImageUpload(self::ASSET_ID);
                $this->fail('expected an ApiException');
            } catch (ApiException $e) {
                $this->assertStringContainsString('did not return a published image', $e->getMessage());
            }
        }
    }

    /**
     * Lock: the image bytes go to the presigned storage URL with exactly the
     * upload headers — never the bearer token or the Ed25519 signature headers.
     */
    public function testUploadImagePutsTheBytesToStorageWithOnlyTheUploadHeaders(): void
    {
        [$links, $http] = $this->make();
        self::queueImageUpload($http);

        $image = $links->uploadImage('kapak.png', PaymentLinkClient::IMAGE_PNG, self::PNG);

        $this->assertSame(self::publishedImage(), $image);
        $this->assertSame([
            'POST ' . self::BASE_URL . '/api/v1/payments/links/images',
            'PUT ' . self::STORAGE_URL,
            'POST ' . self::BASE_URL . '/api/v1/payments/links/images/' . self::ASSET_ID . '/confirm',
        ], array_map(static fn (array $call): string => $call['method'] . ' ' . $call['url'], $http->calls));
        [$create, $put, $confirm] = $http->calls;
        $this->assertSame(strlen(self::PNG), json_decode((string) $create['body'], true)['sizeBytes'], 'the declared size is the byte length');
        $this->assertSame('OK', $this->signatureGuardVerdict($create));
        $this->assertSame('OK', $this->signatureGuardVerdict($confirm));

        $this->assertSame(['Content-Type' => 'image/png'], $put['headers'], 'exactly the upload headers the URL was signed for');
        $this->assertSame([], self::credentialHeaders($put));
        $this->assertSame(self::PNG, $put['body']);
        // The detector is not blind: it flags every credential on a request ApiClient built.
        $flagged = self::credentialHeaders($create);
        sort($flagged);
        $this->assertSame(['Authorization', 'Digest', 'X-Date', 'X-Key-Id', 'X-Nonce', 'X-Signature'], $flagged);
    }

    public function testUploadImageRefusesEmptyContentBeforeAnyRequest(): void
    {
        [$links, $http] = $this->make();

        try {
            $links->uploadImage('kapak.png', PaymentLinkClient::IMAGE_PNG, '');
            $this->fail('expected a ConfigurationException');
        } catch (ConfigurationException $expected) {
            $this->assertStringContainsString('must not be empty', $expected->getMessage());
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testAFailedStoragePutIsAnApiExceptionAndNothingIsConfirmed(): void
    {
        [$links, $http] = $this->make();
        $expired = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>AccessDenied</Code><Message>Request has expired</Message></Error>";
        $http->pushJson(201, self::envelope(self::uploadTarget()));
        $http->push(new HttpResponse(403, ['content-type' => 'application/xml'], $expired));

        try {
            $links->uploadImage('kapak.png', PaymentLinkClient::IMAGE_PNG, self::PNG);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getErrorCode());
            $this->assertStringContainsString('HTTP 403, AccessDenied', $e->getMessage());
        }
        $this->assertSame(['POST', 'PUT'], array_column($http->calls, 'method'), 'the image is not confirmed after a failed upload');

        [$unreachable, $unreachableHttp] = $this->make();
        $unreachableHttp->pushJson(201, self::envelope(self::uploadTarget()));
        $unreachableHttp->push(new ApiException('HTTP transport error (7): Failed to connect'));
        try {
            $unreachable->uploadImage('kapak.png', PaymentLinkClient::IMAGE_PNG, self::PNG);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('storage failed: HTTP transport error (7)', $e->getMessage());
            $this->assertInstanceOf(ApiException::class, $e->getPrevious());
        }
        $this->assertSame(['POST', 'PUT'], array_column($unreachableHttp->calls, 'method'), 'a PUT that never landed is not retried or confirmed');
    }

    public function testTheFacadeSendsTheImagePutOverItsOwnTransport(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $lunixi = LunixiClient::create(['baseUrl' => self::BASE_URL, 'keyId' => 'kid_1', 'privateKey' => $keys['privateKey']], $http, $store);
        $store->set($lunixi->config()->tokenCacheKey(), 'at_test_seeded', 3600);
        self::queueImageUpload($http);

        $lunixi->paymentLinks()->uploadImage('kapak.png', PaymentLinkClient::IMAGE_PNG, self::PNG);

        $this->assertSame(['POST', 'PUT', 'POST'], array_column($http->calls, 'method'), 'the injected transport (e.g. the WordPress adapter) carries the PUT too');
        $this->assertSame([], self::credentialHeaders($http->calls[1]));
    }
}
