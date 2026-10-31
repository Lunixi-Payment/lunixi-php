<?php
declare(strict_types=1);
namespace Lunixi\Sdk\Tests\Commerce;

use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Commerce\CheckoutService;
use Lunixi\Sdk\Commerce\CheckoutSession;
use Lunixi\Sdk\Commerce\Money;
use Lunixi\Sdk\Commerce\OrderSnapshot;
use Lunixi\Sdk\Commerce\PaymentEvidence;
use Lunixi\Sdk\Commerce\PdoLedgerStore;
use Lunixi\Sdk\Commerce\SecretBox;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CommerceTest extends TestCase
{
    private function order(): OrderSnapshot { return new OrderSnapshot('merchant-1', 'OC-123', 1990, 'TRY'); }
    private function payment(string $status = 'CAPTURED', int $amount = 1990): array
    {
        return ['data' => ['id' => 'pi-1', 'merchantId' => 'merchant-1', 'orderId' => 'OC-123', 'amount' => $amount, 'currency' => 'TRY', 'status' => $status], 'details' => ['capturedAmount' => $status === 'CAPTURED' ? (string) $amount : '0', 'refundedAmount' => '0']];
    }
    /** @return array{CheckoutService,FakeHttpClient,PdoLedgerStore,\PDO} */
    private function service(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration(['baseUrl' => 'https://gateway.example', 'keyId' => 'kid-test', 'privateKey' => $keys['privateKey']]);
        $tokens = new InMemoryTokenStore(); $tokens->set($config->tokenCacheKey(), 'bearer-test', 3600);
        $http = new FakeHttpClient();
        $db = new \PDO('sqlite::memory:');
        $store = new PdoLedgerStore($db); $store->install();
        $db->exec('CREATE TABLE orders (id TEXT PRIMARY KEY, paid INTEGER NOT NULL DEFAULT 0)');
        $db->exec("INSERT INTO orders(id) VALUES ('123')");
        return [new CheckoutService(new LunixiClient($config, $http, $tokens), $store, new SecretBox(str_repeat('k', 32))), $http, $store, $db];
    }
    public function testMoneyDoesNotUseFloatingPointOrRoundAwayFractionalCents(): void
    {
        self::assertSame(1990, Money::minor('19.90'));
        self::assertSame(1990, Money::minor('19.9000'));
        self::assertSame(9007199254740991, Money::minor('90071992547409.91'));
        self::assertSame('19.90', Money::decimal(1990));
        self::assertSame(19, Money::minor('19', 0));
        foreach (['19.901', '-1', '1e2', ' 1', '01', '90071992547409.92'] as $bad) {
            try { Money::minor($bad); self::fail('Accepted invalid money.'); } catch (\InvalidArgumentException $expected) { self::assertNotSame('', $expected->getMessage()); }
        }
        $this->expectException(\InvalidArgumentException::class); Money::integer(19.9);
    }
    public function testPaymentIdentityAndFullCaptureAreRequired(): void
    {
        self::assertTrue(PaymentEvidence::evaluate($this->order(), 'pi-1', $this->payment())->isPaid());
        self::assertSame('review', PaymentEvidence::evaluate($this->order(), 'pi-1', $this->payment('PARTIALLY_CAPTURED'))->state);
        $p = $this->payment(); $p['data']['merchantId'] = 'another-merchant';
        $this->expectException(\RuntimeException::class); PaymentEvidence::evaluate($this->order(), 'pi-1', $p);
    }
    public function testCurrencyConversionUsesPersistedDecimalRatesAndExactHalfUpRounding(): void
    {
        self::assertSame(20013, Money::convertedMinor('200.1250', '1.00000000'));
        self::assertSame(33, Money::convertedMinor('0.1000', '3.33333333'));
        self::assertSame(125, Money::convertedMinor('1.0000', '125.00000000', 0));
        self::assertSame(1235, Money::convertedMinor('1.2345', '1.00000000', 3));
        $this->expectException(\InvalidArgumentException::class); Money::convertedMinor(1.25, '1.0');
    }
    public function testCurrencyExponentsAndUnknownCurrencyFailClosed(): void
    {
        self::assertSame(2, Money::exponent('TRY'));
        self::assertSame(0, Money::exponent('JPY'));
        self::assertSame(3, Money::exponent('KWD'));
        $this->expectException(\InvalidArgumentException::class); Money::exponent('ZZZ');
    }
    public function testExtraInstallmentFeeRequiresAuthenticatedOriginalOrderFingerprint(): void
    {
        $p = $this->payment('CAPTURED', 2100);
        try { PaymentEvidence::evaluate($this->order(), 'pi-1', $p); self::fail('Accepted unexplained overcharge.'); } catch (\RuntimeException $expected) {}
        $p['data']['metadataJson'] = json_encode(['lunixiCommerce' => ['fingerprint' => $this->order()->fingerprint()]]);
        self::assertSame(2100, PaymentEvidence::evaluate($this->order(), 'pi-1', $p)->chargedAmount);
    }
    public function testOneCapturedSplitLegNeverCompletesAnOrder(): void
    {
        $p = $this->payment('SETTLED_BY_SPLIT');
        $p['data']['split'] = ['role' => 'PARENT', 'splitPaymentId' => 'split-1'];
        $s = ['id' => 'split-1', 'orderId' => 'OC-123', 'currency' => 'TRY', 'status' => 'PROCESSING', 'needsReconciliation' => false, 'aggregate' => ['totalAmount' => 1990, 'legsAmount' => 1990, 'amountsBalanced' => true, 'capturedAmount' => 1000, 'compensatedAmount' => 0]];
        self::assertSame('pending', PaymentEvidence::evaluate($this->order(), 'pi-1', $p, $s)->state);
        $s['status'] = 'COMPLETED'; $s['aggregate']['capturedAmount'] = 1990;
        self::assertTrue(PaymentEvidence::evaluate($this->order(), 'pi-1', $p, $s)->isPaid());
        $p['data']['split']['role'] = 'LEG';
        $this->expectException(\RuntimeException::class); PaymentEvidence::evaluate($this->order(), 'pi-1', $p, $s);
    }
    public function testReservationSurvivesTimeoutAndReusesExactKeyAndBody(): void
    {
        [$service, $http, $store] = $this->service();
        $http->push(new \RuntimeException('Simulated connection loss'));
        try { $service->start('123', $this->order(), 'https://shop.example/return'); self::fail('Expected timeout.'); } catch (\RuntimeException $expected) {}
        $first = $http->lastCall();
        $reserved = $store->transaction('123', function () use ($store) { return $store->load('123'); });
        self::assertSame('reserved', $reserved['state']);
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $session = $service->start('123', $this->order(), 'https://shop.example/return');
        self::assertSame($first['headers']['Idempotency-Key'], $http->lastCall()['headers']['Idempotency-Key']);
        self::assertSame($first['body'], $http->lastCall()['body']);
        self::assertSame('browser-token', $session->token);
        $stored = $store->transaction('123', function () use ($store) { return $store->load('123'); });
        self::assertStringNotContainsString('browser-token', json_encode($stored));
        self::assertSame('browser-token', $service->start('123', $this->order(), 'https://shop.example/return')->token);
        self::assertCount(2, $http->calls);
    }
    public function testChangedOrderCannotReuseTheReservedAttempt(): void
    {
        [$service, $http] = $this->service();
        $http->push(new \RuntimeException('timeout'));
        try { $service->start('123', $this->order(), 'https://shop.example/return'); } catch (\RuntimeException $expected) {}
        $this->expectException(\RuntimeException::class);
        $service->start('123', new OrderSnapshot('merchant-1', 'OC-123', 2990, 'TRY'), 'https://shop.example/return');
    }
    public function testWebhookAndOrderUpdateRollbackTogetherThenDuplicateIsHarmless(): void
    {
        [$service, $http, $store, $db] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return');
        $body = json_encode(['id' => 'evt-1', 'type' => 'payment.captured', 'data' => ['paymentId' => 'pi-1']]);
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $headers = ['x-lunixi-event-id' => 'evt-1', 'x-lunixi-signature-timestamp' => $ts, 'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', 'evt-1.' . $ts . '.' . $body, 'secret')];
        $event = $service->verifyWebhook($body, $headers, 'secret');
        $http->pushJson(200, $this->payment());
        try {
            $service->reconcile('123', function () use ($db): void { $db->exec("UPDATE orders SET paid = 1 WHERE id = '123'"); throw new \RuntimeException('Native order write failed'); }, $event);
            self::fail('Expected rollback.');
        } catch (\RuntimeException $expected) {}
        self::assertSame(0, (int) $db->query("SELECT paid FROM orders WHERE id = '123'")->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM lunixi_events')->fetchColumn());
        $applied = 0;
        for ($i = 0; $i < 2; ++$i) {
            $http->pushJson(200, $this->payment());
            $service->reconcile('123', function () use ($db, &$applied): void { ++$applied; $db->exec("UPDATE orders SET paid = 1 WHERE id = '123'"); }, $event);
        }
        self::assertSame(1, $applied);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM lunixi_events')->fetchColumn());
    }
    public function testSignedEnvelopeIdCannotBeReplacedEvenWithAValidHmac(): void
    {
        [$service] = $this->service();
        $body = '{"id":"other-event","type":"payment.captured","data":{}}'; $ts = gmdate('c');
        $headers = ['x-lunixi-event-id' => 'evt-1', 'x-lunixi-signature-timestamp' => $ts, 'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', 'evt-1.' . $ts . '.' . $body, 'secret')];
        $this->expectException(\RuntimeException::class); $service->verifyWebhook($body, $headers, 'secret');
    }
    public function testEventIdentityCollisionRollsBackInsteadOfAcknowledgingChangedContent(): void
    {
        [, , $store, $db] = $this->service();
        self::assertTrue($store->transaction('123', function () use ($store) { return $store->claimEvent('merchant-1', 'evt-1', hash('sha256', 'original')); }));
        self::assertFalse($store->transaction('123', function () use ($store) { return $store->claimEvent('merchant-1', 'evt-1', hash('sha256', 'original')); }));
        try { $store->transaction('123', function () use ($store) { $store->claimEvent('merchant-1', 'evt-1', hash('sha256', 'different')); }); self::fail('Accepted colliding event content.'); }
        catch (\RuntimeException $expected) { self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM lunixi_events')->fetchColumn()); }
    }
    public function testExpiredUnknownCreateNeverMakesAnotherFinancialRequest(): void
    {
        [$service, $http, $store] = $this->service();
        $http->push(new \RuntimeException('Lost create response'));
        try { $service->start('123', $this->order(), 'https://shop.example/return'); self::fail('Expected timeout.'); } catch (\RuntimeException $expected) {}
        $store->transaction('123', function () use ($store): void { $r = $store->load('123'); $r['createdAt'] = time() - 23 * 3600; $store->save('123', $r); });
        $count = count($http->calls);
        try { $service->start('123', $this->order(), 'https://shop.example/return'); self::fail('Replayed beyond retention.'); }
        catch (\RuntimeException $expected) { self::assertStringContainsString('replay window', $expected->getMessage()); }
        self::assertCount($count, $http->calls);
    }
    public function testExpiredOrMissingOperationTimestampAllowsOnlyAuthenticatedReconciliation(): void
    {
        [$service, $http, $store] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return');
        $http->pushJson(200, $this->payment()); $http->push(new \RuntimeException('Lost refund response'));
        try { $service->mutate('123', 'refund-stable', 'refund', 990, static function (): void {}); self::fail('Expected timeout.'); } catch (\RuntimeException $expected) {}
        foreach ([time() - 23 * 3600, null, time() + 60] as $timestamp) {
            $store->transaction('123', function () use ($store, $timestamp): void { $r = $store->load('123'); $r['operations'][hash('sha256', 'refund-stable')]['createdAt'] = $timestamp; $store->save('123', $r); });
            $count = count($http->calls);
            try { $service->mutate('123', 'refund-stable', 'refund', 990, static function (): void {}); self::fail('Replayed beyond retention.'); }
            catch (\RuntimeException $expected) { self::assertStringContainsString('replay window', $expected->getMessage()); }
            self::assertCount($count, $http->calls);
        }
        $p = $this->payment('PARTIALLY_REFUNDED'); $p['details'] = ['capturedAmount' => '1990', 'refundedAmount' => '990'];
        $http->pushJson(200, $p); $service->reconcile('123', static function (): void {});
        self::assertSame('confirmed', $service->operationStatus('123', 'refund-stable')['state']);
    }
    public function testRefundTimeoutReusesPersistedOperationKeyAndReconcilesActualBalance(): void
    {
        [$service, $http] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return');
        $http->pushJson(200, $this->payment());
        $http->push(new \RuntimeException('Lost refund response'));
        try { $service->mutate('123', 'refund-record-10', 'refund', 990, static function (): void {}); self::fail('Expected timeout.'); } catch (\RuntimeException $expected) {}
        $first = $http->lastCall();
        self::assertStringEndsWith('/pi-1/refund', $first['url']);
        $http->pushJson(200, ['data' => ['id' => 'pi-1', 'status' => 'PARTIALLY_REFUNDED']]);
        $p = $this->payment('PARTIALLY_REFUNDED'); $p['details'] = ['capturedAmount' => '1990', 'refundedAmount' => '990'];
        $http->pushJson(200, $p);
        $applied = [];
        $result = $service->mutate('123', 'refund-record-10', 'refund', 990, static function ($d) use (&$applied): void { $applied[] = $d->state; });
        self::assertSame($first['headers']['Idempotency-Key'], $http->calls[count($http->calls) - 2]['headers']['Idempotency-Key']);
        self::assertSame('partially_refunded', $result->state);
        self::assertSame(['partially_refunded'], $applied);
        self::assertSame('confirmed', $service->operationStatus('123', 'refund-record-10')['state']);
        $this->expectException(\RuntimeException::class);
        $service->mutate('123', 'refund-record-10', 'refund', 500, static function (): void {});
    }
    public function testAsyncRefundStaysPendingAndBlocksFreshOperationIdsUntilVerified(): void
    {
        [$service, $http] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return');
        $http->pushJson(200, $this->payment());
        $http->pushJson(200, ['data' => ['id' => 'pi-1', 'status' => 'CAPTURED']]);
        $http->pushJson(200, $this->payment());
        $service->mutate('123', 'refund-async', 'refund', 990, static function (): void {});
        $op = $service->operationStatus('123', 'refund-async');
        self::assertSame('pending', $op['state']); self::assertArrayNotHasKey('key', $op);
        $http->pushJson(200, $this->payment());
        try { $service->mutate('123', 'another-refund', 'refund', 990, static function (): void {}); self::fail('Allowed a second unresolved refund.'); } catch (\RuntimeException $expected) {}
        $p = $this->payment('PARTIALLY_REFUNDED'); $p['details'] = ['capturedAmount' => '1990', 'refundedAmount' => '990'];
        $http->pushJson(200, $p);
        $service->reconcile('123', static function (): void {});
        self::assertSame('confirmed', $service->operationStatus('123', 'refund-async')['state']);
    }
    public function testAuthorizedVoidIsConfirmedOnlyAfterAuthenticatedRelease(): void
    {
        [$service, $http] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return', false, true);
        $http->pushJson(200, $this->payment('AUTHORIZED'));
        $service->reconcile('123', static function (): void {});
        $http->pushJson(200, $this->payment('AUTHORIZED'));
        $http->pushJson(200, ['data' => ['id' => 'pi-1', 'status' => 'VOIDED']]);
        $http->pushJson(200, $this->payment('VOIDED'));
        $decision = $service->mutate('123', 'native-void-1', 'void', null, static function (): void {});
        self::assertSame('failed', $decision->state);
        self::assertSame('VOIDED', $decision->gatewayStatus);
        self::assertSame('confirmed', $service->operationStatus('123', 'native-void-1')['state']);
    }
    public function testPartialCaptureRemainsUnpaidAndLaterCaptureUsesRemainingBalance(): void
    {
        [$service, $http] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return', false, true);
        $http->pushJson(200, $this->payment('AUTHORIZED'));
        $http->pushJson(200, ['data' => ['id' => 'pi-1', 'status' => 'PARTIALLY_CAPTURED']]);
        $partial = $this->payment('PARTIALLY_CAPTURED'); $partial['details']['capturedAmount'] = '990';
        $http->pushJson(200, $partial);
        $first = $service->mutate('123', 'capture-part-1', 'capture', 990, static function (): void {});
        self::assertSame('partially_captured', $first->state); self::assertFalse($first->isPaid());
        self::assertSame('confirmed', $service->operationStatus('123', 'capture-part-1')['state']);
        $http->pushJson(200, $partial);
        $http->pushJson(200, ['data' => ['id' => 'pi-1', 'status' => 'CAPTURED']]);
        $http->pushJson(200, $this->payment());
        self::assertTrue($service->mutate('123', 'capture-part-2', 'capture', 1000, static function (): void {})->isPaid());
    }
    public function testNewAttemptRequiresAuthenticatedTerminalFailure(): void
    {
        [$service, $http, $store] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return');
        $first = $store->transaction('123', function () use ($store) { return $store->load('123'); });
        $http->pushJson(200, $this->payment('PROCESSING'));
        try { $service->resetFailedAttempt('123'); self::fail('Reset a pending payment.'); } catch (\RuntimeException $expected) {}
        $unchanged = $store->transaction('123', function () use ($store) { return $store->load('123'); });
        self::assertSame($first['key'], $unchanged['key']);
        $http->pushJson(200, $this->payment('FAILED'));
        try { $service->resetFailedAttempt('123'); self::fail('FAILED alone is not definitive provider outcome evidence.'); } catch (\RuntimeException $expected) {}
        self::assertSame('review', PaymentEvidence::evaluate($this->order(), 'pi-1', $this->payment('FAILED'))->state);
        $http->pushJson(200, $this->payment('VOIDED'));
        $service->resetFailedAttempt('123');
        $next = $store->transaction('123', function () use ($store) { return $store->load('123'); });
        self::assertNotSame($first['key'], $next['key']);
        self::assertSame('', $next['paymentId']);
        self::assertSame('pi-1', $next['history'][0]['paymentId']);
    }
    public function testDelayedPendingReadCannotDowngradePaidOrder(): void
    {
        [$service, $http] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return');
        $http->pushJson(200, $this->payment());
        $service->reconcile('123', static function (): void {});
        $http->pushJson(200, $this->payment('PROCESSING'));
        $applied = false;
        $decision = $service->reconcile('123', static function () use (&$applied): void { $applied = true; });
        self::assertTrue($decision->isPaid()); self::assertFalse($applied);
    }
    private function verifiedEvent(CheckoutService $service, array $data)
    {
        $body = json_encode(['id' => 'evt-recovery', 'type' => 'payment.captured', 'data' => $data]); $ts = gmdate('c');
        return $service->verifyWebhook($body, ['x-lunixi-event-id' => 'evt-recovery', 'x-lunixi-signature-timestamp' => $ts, 'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', 'evt-recovery.' . $ts . '.' . $body, 'secret')], 'secret');
    }
    public function testLostCreationResponseCanRecoverOnlyItsAuthenticatedReservedIntent(): void
    {
        [$service, $http, $store, $db] = $this->service();
        $http->push(new \RuntimeException('lost response'));
        try { $service->start('123', $this->order(), 'https://shop.example/return'); } catch (\RuntimeException $expected) {}
        $request = json_decode($http->lastCall()['body'], true);
        $p = $this->payment(); $p['data']['metadataJson'] = json_encode($request['metadata']);
        $event = $this->verifiedEvent($service, ['intentId' => 'pi-1']);
        $http->pushJson(200, $p);
        try { $service->reconcile('123', static function (): void { throw new \RuntimeException('native order failed'); }, $event); self::fail('Expected rollback.'); } catch (\RuntimeException $expected) {}
        self::assertSame('', $store->transaction('123', fn () => $store->load('123'))['paymentId']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM lunixi_events')->fetchColumn());
        $http->pushJson(200, $p);
        self::assertTrue($service->reconcile('123', static function (): void {}, $event)->isPaid());
        self::assertSame('pi-1', $store->transaction('123', fn () => $store->load('123'))['paymentId']);
    }
    public function testMatchingOrderFingerprintCannotBindADifferentReservation(): void
    {
        [$service, $http, $store] = $this->service();
        $http->push(new \RuntimeException('lost response'));
        try { $service->start('123', $this->order(), 'https://shop.example/return'); } catch (\RuntimeException $expected) {}
        $p = $this->payment(); $p['data']['metadataJson'] = json_encode(['lunixiCommerce' => ['fingerprint' => $this->order()->fingerprint(), 'attempt' => str_repeat('a', 64)]]);
        $http->pushJson(200, $p);
        try { $service->reconcile('123', static function (): void {}, $this->verifiedEvent($service, ['intentId' => 'pi-1'])); self::fail('Bound a different reservation.'); }
        catch (\RuntimeException $expected) { self::assertSame('', $store->transaction('123', fn () => $store->load('123'))['paymentId']); }
    }
    public function testConflictingWebhookPaymentAliasesFailBeforeAnyStatusRequest(): void
    {
        [$service, $http] = $this->service();
        $http->pushJson(201, ['data' => ['paymentId' => 'pi-1', 'token' => 'browser-token']]);
        $service->start('123', $this->order(), 'https://shop.example/return');
        try { $service->reconcile('123', static function (): void {}, $this->verifiedEvent($service, ['intentId' => 'pi-1', 'paymentId' => 'another-payment'])); self::fail('Accepted conflicting identities.'); }
        catch (\RuntimeException $expected) { self::assertCount(1, $http->calls); }
    }
    public function testSecretBoxAuthenticatesCiphertextAndBrowserJsonEscapesScriptEndTags(): void
    {
        $session = new CheckoutSession('pi-1', '</script><script>alert(1)</script>');
        self::assertStringNotContainsString('<', $session->browserConfig());
        self::assertStringStartsWith('https://pay.lunixi.com/payment/', $session->hostedUrl());
        $box = new SecretBox(str_repeat('k', 32));
        $cipher = $box->encrypt('private-value'); self::assertSame('private-value', $box->decrypt($cipher));
        $raw = base64_decode($cipher); $raw[30] = chr(ord($raw[30]) ^ 1);
        $this->expectException(\RuntimeException::class); $box->decrypt(base64_encode($raw));
    }
}
