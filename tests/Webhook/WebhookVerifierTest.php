<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Webhook;

use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Locks the webhook verification against the gateway's WebhookSignatureService:
 * v2 = hex HMAC-SHA256(secret, "<eventId>.<timestamp>.<rawBody>") over the EXACT
 * raw body bytes, delivered as `v2=` item(s) in a comma-separated header. The
 * shared vectors in fixtures/webhook-v2-vectors.json are cross-SDK known-answers
 * (the same file ships in the Node SDK test suite).
 */
final class WebhookVerifierTest extends TestCase
{
    /** @return array<int,array<string,string>> shared cross-SDK vectors */
    private static function vectors(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/webhook-v2-vectors.json');
        /** @var array<int,array<string,string>> $vectors */
        $vectors = json_decode($json, true);
        return $vectors;
    }

    /** @return array<string,string> */
    private static function vector(string $name): array
    {
        foreach (self::vectors() as $v) {
            if ($v['name'] === $name) {
                return $v;
            }
        }
        throw new \RuntimeException('unknown vector ' . $name);
    }

    /** @param array<string,string> $v @return array<string,string> */
    private static function v2Headers(array $v, string $signatureHeader): array
    {
        return [
            'X-Lunixi-Event-Id' => $v['eventId'],
            'X-Lunixi-Event-Type' => 'payment.captured',
            'X-Lunixi-Signature' => $signatureHeader,
            'X-Lunixi-Signature-Timestamp' => $v['timestamp'],
        ];
    }

    /**
     * Produces the headers the gateway would deliver, using the gateway's exact
     * algorithm: v2 = HMAC over "<eventId>.<timestamp>.<rawBody>".
     *
     * @return array<string,string>
     */
    private static function sign(string $body, string $eventId, string $timestamp, string $secret): array
    {
        $sig = hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $body, $secret);

        return [
            'X-Lunixi-Event-Id' => $eventId,
            'X-Lunixi-Event-Type' => 'payment.captured',
            'X-Lunixi-Signature' => 'v2=' . $sig,
            'X-Lunixi-Signature-Timestamp' => $timestamp,
        ];
    }

    // ── shared cross-SDK vectors ──

    public function testV2SharedVectorsVerify(): void
    {
        foreach (self::vectors() as $v) {
            // Vector timestamps are fixed (in the past) — disable the freshness
            // check here; tolerance behaviour has its own tests.
            $event = (new WebhookVerifier())->verify(
                $v['rawBody'],
                self::v2Headers($v, 'v2=' . $v['expectedV2']),
                $v['secret'],
                null
            );
            $this->assertSame($v['eventId'], $event->id(), $v['name']);
        }
    }

    public function testV2RawBodyIsNotReserializedForSigning(): void
    {
        $v = self::vector('unicode-and-slashes');
        // Same logical JSON, different bytes → must FAIL (byte-sensitive).
        $reserialized = (string) json_encode(json_decode($v['rawBody'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify($reserialized, self::v2Headers($v, 'v2=' . $v['expectedV2']), $v['secret'], null);
    }

    public function testV2RotationHeaderVerifiesUnderEitherSecret(): void
    {
        $current = self::vector('basic-payment-captured');
        $previous = self::vector('rotated-previous-secret');
        $rotatedHeader = 'v2=' . $current['expectedV2'] . ',v2=' . $previous['expectedV2'];

        foreach ([$current, $previous] as $v) {
            $event = (new WebhookVerifier())->verify($v['rawBody'], self::v2Headers($v, $rotatedHeader), $v['secret'], null);
            $this->assertSame('payment.captured', $event->type());
        }
    }

    public function testRotatedPreviousSecretRejectsNewSecretSignatureAlone(): void
    {
        $current = self::vector('basic-payment-captured');
        $previous = self::vector('rotated-previous-secret');
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify(
            $previous['rawBody'],
            self::v2Headers($previous, 'v2=' . $current['expectedV2']),
            $previous['secret'],
            null
        );
    }

    public function testHeaderWithOnlySha256ItemIsRejected(): void
    {
        // No v1 scheme exists on the wire; a header without a v2 item ALWAYS fails.
        $v = self::vector('basic-payment-captured');
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify(
            $v['rawBody'],
            self::v2Headers($v, 'sha256=' . str_repeat('0', 64)),
            $v['secret'],
            null
        );
    }

    public function testWrongV2RejectsEvenWithOtherUnknownItemsPresent(): void
    {
        $v = self::vector('basic-payment-captured');
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify(
            $v['rawBody'],
            self::v2Headers($v, 'v2=' . str_repeat('0', 64) . ',sha256=' . str_repeat('0', 64)),
            $v['secret'],
            null
        );
    }

    public function testWhitespaceAroundHeaderItemsIsTolerated(): void
    {
        $v = self::vector('basic-payment-captured');
        $event = (new WebhookVerifier())->verify(
            $v['rawBody'],
            self::v2Headers($v, ' v2=' . $v['expectedV2'] . ' , v2=deadbeef '),
            $v['secret'],
            null
        );
        $this->assertSame('payment.captured', $event->type());
    }

    // ── full verify round-trip ──

    public function testAcceptsAValidSignature(): void
    {
        $secret = 'whsec_test_123';
        $body = '{"id":"evt_1","type":"payment.captured","data":{"intentId":"pi_1","amount":1000,"currency":"TRY"}}';
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $headers = self::sign($body, 'evt_1', $timestamp, $secret);

        $event = (new WebhookVerifier())->verify($body, $headers, $secret);

        $this->assertSame('evt_1', $event->id());
        $this->assertSame('payment.captured', $event->type());
        $this->assertSame('pi_1', $event->payload()['intentId']);
        $this->assertTrue($event->matches('payment.*'));
        $this->assertTrue($event->matches('payment.captured'));
        $this->assertFalse($event->matches('refund.*'));
    }

    public function testRejectsTamperedBody(): void
    {
        $secret = 'whsec_x';
        $body = '{"amount":1000}';
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $headers = self::sign($body, 'evt_2', $ts, $secret);

        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify('{"amount":9999}', $headers, $secret);
    }

    public function testRejectsWrongSecret(): void
    {
        $body = '{"a":1}';
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $headers = self::sign($body, 'evt_3', $ts, 'right');

        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify($body, $headers, 'wrong');
    }

    public function testRejectsStaleTimestamp(): void
    {
        $secret = 'whsec_y';
        $body = '{"a":1}';
        $ts = gmdate('Y-m-d\TH:i:s\Z', time() - 4000); // outside 300s
        $headers = self::sign($body, 'evt_4', $ts, $secret);

        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify($body, $headers, $secret, 300);
    }

    public function testStaleTimestampAcceptedWhenToleranceDisabled(): void
    {
        $secret = 'whsec_z';
        $body = '{"a":1}';
        $ts = gmdate('Y-m-d\TH:i:s\Z', time() - 999999);
        $headers = self::sign($body, 'evt_5', $ts, $secret);

        $event = (new WebhookVerifier())->verify($body, $headers, $secret, null);
        $this->assertSame('evt_5', $event->id());
    }

    public function testRejectsMissingHeaders(): void
    {
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier())->verify('{"a":1}', [], 'secret');
    }

    public function testEnvelopeExposesDataAndAuthenticatedTypeFromBody(): void
    {
        $secret = 'whsec_env';
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $body = (string) json_encode([
            'id' => 'evt_env_1',
            'type' => 'payment.captured',
            'environment' => 'LIVE',
            'data' => ['intentId' => 'pi_77', 'amountMinor' => '1500', 'currency' => 'TRY', 'transactionId' => 'TXN-9'],
        ]);
        $headers = self::sign($body, 'evt_env_1', $ts, $secret);

        $event = (new WebhookVerifier())->verify($body, $headers, $secret);

        $this->assertSame('evt_env_1', $event->id());
        $this->assertSame('payment.captured', $event->type());
        $this->assertSame('pi_77', $event->data()['intentId']);
        $this->assertSame('1500', $event->data()['amountMinor']); // minor units as string
        $this->assertSame('TXN-9', $event->data()['transactionId']);
        $this->assertSame('LIVE', $event->envelope()['environment']);
        $this->assertSame($event->data(), $event->payload()); // payload() aliases data()
    }

    public function testEventTypeComesFromSignedBodyNotTheUnsignedHeader(): void
    {
        // The x-lunixi-event-type header is NOT part of the HMAC; only the body is.
        // A tampered type header must be ignored in favour of the authenticated body.
        $secret = 'whsec_auth';
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $body = (string) json_encode(['id' => 'e1', 'type' => 'payment.refunded', 'data' => ['intentId' => 'pi_1']]);
        $headers = self::sign($body, 'e1', $ts, $secret);
        $headers['X-Lunixi-Event-Type'] = 'payment.captured'; // spoofed header

        $event = (new WebhookVerifier())->verify($body, $headers, $secret);

        $this->assertSame('payment.refunded', $event->type(), 'authenticated body type must win over the spoofed header');
    }
}
