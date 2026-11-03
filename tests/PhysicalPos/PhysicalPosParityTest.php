<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\PhysicalPos;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Exception\LunixiException;
use Lunixi\Sdk\Exception\PhysicalPosCardDataRejectedException;
use Lunixi\Sdk\PhysicalPos\PhysicalPosClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosEvents;
use Lunixi\Sdk\PhysicalPos\PhysicalPosManagementClient;
use Lunixi\Sdk\PhysicalPos\PhysicalPosRequest;
use Lunixi\Sdk\PhysicalPos\PhysicalPosWebhookEvent;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Cross-SDK parity: fixtures/physical-pos-parity.json is byte-identical in the
 * Node, PHP, Java and Python SDKs. Its expected values were computed from the
 * gateway rules (card-data key tokens, the body guard's field path, the PAN
 * value scan), so a change on either side turns this test red.
 */
final class PhysicalPosParityTest extends TestCase
{
    private const KEY = 'pos-parity-key-0001';

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/physical-pos-parity.json');
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'physical-pos-parity.json must be valid JSON');

        return $decoded;
    }

    /** @return array{0:PhysicalPosClient,1:FakeHttpClient} */
    private static function make(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration([
            'baseUrl' => 'https://gw.example.com', 'keyId' => 'mk_live_sk_kid_1', 'privateKey' => $keys['privateKey'], 'maxRetries' => 0,
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $store->set($config->tokenCacheKey(), 'at_test_seeded', 3600);

        return [new PhysicalPosClient(new ApiClient($config, $signer, new TokenManager($config, $signer, $http, $store), $http)), $http];
    }

    /** @return array<string,mixed> */
    private static function sale(): array
    {
        return ['operation' => 'SALE', 'target' => ['deviceId' => 'dev-1'], 'amountMinor' => "100", 'currency' => 'TRY'];
    }

    /** @return string[] */
    private static function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }

    public function testCardDataTermListsMatchTheGateway(): void
    {
        $rule = self::fixture()['cardDataKeyRule'];
        $class = new \ReflectionClass(PhysicalPosRequest::class);
        $this->assertSame(self::sorted($rule['numberTerms']), self::sorted($class->getConstant('CARD_NUMBER_TERMS')));
        $this->assertSame(self::sorted($rule['secretTerms']), self::sorted($class->getConstant('CARD_SECRET_TERMS')));
        $this->assertSame(self::sorted($rule['suffixTerms']), self::sorted($class->getConstant('CARD_SUFFIX_TERMS')));
        $this->assertSame(self::sorted($rule['wholeKeyTerms']), self::sorted($class->getConstant('WHOLE_KEY_TERMS')));
        foreach ($rule['maskTokens'] as $token) {
            $this->assertFalse(PhysicalPosRequest::isCardDataKey($token . 'Pan'), $token);
        }
    }

    public function testCardDataKeyCases(): void
    {
        foreach (self::fixture()['cardDataKeyCases'] as $case) {
            $this->assertSame($case['cardData'], PhysicalPosRequest::isCardDataKey($case['key']), $case['key']);
        }
    }

    public function testCardDataPathCases(): void
    {
        foreach (self::fixture()['cardDataPathCases'] as $case) {
            $this->assertSame($case['field'], PhysicalPosRequest::findCardDataField($case['body']), (string) json_encode($case['body']));
        }
    }

    public function testPanValueCases(): void
    {
        foreach (self::fixture()['panValueCases'] as $case) {
            $mode = $case['mode'] === 'ITEM_TEXT' ? PhysicalPosRequest::PAN_ITEM_TEXT : PhysicalPosRequest::PAN_STRICT;
            $this->assertSame($case['pan'], PhysicalPosRequest::containsPanLikeValue($case['value'], $mode), $case['mode'] . ' ' . $case['value']);
        }
    }

    public function testForbiddenFieldsAreRefusedBeforeSending(): void
    {
        [$client, $http] = self::make();
        foreach (self::fixture()['forbiddenFields'] as $field) {
            try {
                $client->createPayment(array_merge(self::sale(), [$field => 'x']), self::KEY);
                $this->fail("{$field} must be refused");
            } catch (ConfigurationException | PhysicalPosCardDataRejectedException $error) {
                $this->assertInstanceOf(LunixiException::class, $error);
            }
        }
        $this->assertSame(0, $http->callCount());
    }

    public function testIdempotencyKeyCases(): void
    {
        foreach (self::fixture()['idempotencyKeyCases'] as $case) {
            $this->assertSame($case['valid'], PhysicalPosRequest::isValidIdempotencyKey($case['key']), json_encode($case['key']));
            if (!$case['valid']) {
                try {
                    PhysicalPosRequest::requireIdempotencyKey($case['key'], 'parity');
                    $this->fail('expected ConfigurationException for ' . json_encode($case['key']));
                } catch (ConfigurationException $error) {
                    if (trim($case['key']) !== '') {
                        $this->assertStringNotContainsString($case['key'], $error->getMessage(), 'the key is not echoed');
                    }
                }
            } else {
                $this->assertSame($case['key'], PhysicalPosRequest::requireIdempotencyKey($case['key'], 'parity'));
            }
        }
    }

    public function testEventTypes(): void
    {
        $this->assertSame(self::fixture()['eventTypes'], PhysicalPosEvents::ALL);
    }

    public function testWebhookOriginalCases(): void
    {
        $cases = self::fixture()['webhookOriginalCases'];
        $this->assertGreaterThan(5, count($cases));
        foreach ($cases as $case) {
            $this->assertSame($case['original'], PhysicalPosRequest::webhookOriginal($case['data']), $case['name']);
        }
    }

    public function testWebhookCashierCases(): void
    {
        $cases = self::fixture()['webhookCashierCases'];
        $this->assertGreaterThan(5, count($cases));
        foreach ($cases as $case) {
            $this->assertSame($case['cashier'], PhysicalPosRequest::webhookCashier($case['data']), $case['name']);
        }
    }

    public function testWebhookCategoryCases(): void
    {
        foreach (self::fixture()['webhookCategoryCases'] as $case) {
            $this->assertSame($case['category'], PhysicalPosRequest::webhookCategory($case['type']), $case['type']);
        }
    }

    public function testNotStartedCodes(): void
    {
        $this->assertSame(self::sorted(self::fixture()['notStartedCodes']), self::sorted(PhysicalPosRequest::NOT_STARTED_CODES));
    }

    public function testRequestFieldsMatch(): void
    {
        $expected = self::fixture()['requestFields'];
        $this->assertSame($expected['createPayment'], PhysicalPosClient::PAYMENT_FIELDS);
        $this->assertSame($expected['paymentTarget'], PhysicalPosClient::TARGET_FIELDS);
        $this->assertSame($expected['saleItem'], PhysicalPosClient::SALE_ITEM_FIELDS);
        $this->assertSame($expected['createReversal'], PhysicalPosClient::REVERSAL_FIELDS);
        $this->assertSame($expected['refundItem'], PhysicalPosClient::REFUND_ITEM_FIELDS);

        $management = PhysicalPosManagementClient::requestFields();
        foreach ([
            'createStore', 'updateStore', 'statusChange', 'createRegister', 'updateRegister', 'createTerminal', 'updateTerminal',
            'addTerminalAcquirer', 'updateTerminalAcquirer', 'changeTerminalDevice', 'createDevice', 'updateDevice', 'assignDevice',
            'changeDeviceLifecycle', 'revokeDeviceKey', 'revertDeviceEnrollment', 'revokeActivationCode',
            'addTerminalProvider', 'updateTerminalProvider', 'setSettlementPolicy',
        ] as $name) {
            $this->assertArrayHasKey($name, $management, $name);
            $this->assertSame($expected[$name], $management[$name], $name);
        }
    }

    public function testFiltersMatch(): void
    {
        $expected = self::fixture()['filters'];
        $actual = array_merge(PhysicalPosClient::FILTERS, PhysicalPosManagementClient::FILTERS);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testPathIdCases(): void
    {
        foreach (self::fixture()['pathIdCases'] as $case) {
            if ($case['valid']) {
                $this->assertSame($case['encoded'] ?? $case['id'], PhysicalPosRequest::pathId($case['id'], 'id'), $case['id']);
                continue;
            }
            try {
                PhysicalPosRequest::pathId($case['id'], 'id');
                $this->fail('expected ConfigurationException for ' . json_encode($case['id']));
            } catch (ConfigurationException $error) {
                $this->assertInstanceOf(ConfigurationException::class, $error);
            }
        }
    }
}
