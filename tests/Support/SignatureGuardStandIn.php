<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Support;

/**
 * Stand-in for the gateway's SignatureGuard, shared by the tests of clients
 * whose routes accept only the API key's per-request Ed25519 signature.
 */
final class SignatureGuardStandIn
{
    /**
     * Raw 32-byte Ed25519 public key from a PEM public key
     * (`Ed25519Signer::generateKeyPair()['publicKey']`).
     */
    public static function rawPublicKey(string $pem): string
    {
        $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        return substr($der, -32);
    }

    /**
     * Verdict of the gateway's SignatureGuard for a recorded call
     * (nest-payment-gateway apps/api-gateway/src/auth/guards/signature.guard.ts):
     * Digest = SHA-256 over JSON.stringify of the PARSED body, canonical string =
     * METHOD, request target (path + query), X-Date, X-Nonce and Digest when the
     * header is present. `Authorization` is ignored by the guard.
     *
     * @param array{method:string,url:string,headers:array<string,string>,body:?string} $call
     * @param string $publicKey Raw 32-byte Ed25519 public key of the signing client.
     * @param string $baseUrl   Gateway base URL the call was sent to.
     * @return string 'OK' or the guard's error code.
     */
    public static function verdict(array $call, string $publicKey, string $baseUrl): string
    {
        $h = $call['headers'];
        foreach (['X-Key-Id', 'X-Date', 'X-Nonce', 'X-Signature'] as $name) {
            if (!isset($h[$name]) || $h[$name] === '') {
                return 'AUTH_HEADERS_MISSING';
            }
        }
        if (abs(time() - (int) strtotime($h['X-Date'])) > 300) {
            return 'INVALID_DATE';
        }
        if ($call['body'] !== null && $call['body'] !== '') {
            // Objects stay objects (`{}` is not `[]`), as in JSON.parse → JSON.stringify.
            $parsed = json_decode($call['body'], false, 512, JSON_THROW_ON_ERROR);
            $nonEmpty = is_object($parsed) ? get_object_vars($parsed) !== [] : $parsed !== [];
            if ($nonEmpty) {
                if (!isset($h['Digest'])) {
                    return 'DIGEST_MISSING';
                }
                if ($h['Digest'] !== 'SHA-256=' . base64_encode(hash('sha256', self::v8Reserialize($call['body']), true))) {
                    return 'INVALID_DIGEST';
                }
            }
        }
        $lines = [strtoupper($call['method']), substr($call['url'], strlen($baseUrl)), 'X-Date:' . $h['X-Date'], 'X-Nonce:' . $h['X-Nonce']];
        if (isset($h['Digest'])) {
            $lines[] = 'Digest:' . $h['Digest'];
        }
        $signature = base64_decode($h['X-Signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'INVALID_SIGNATURE';
        }

        return sodium_crypto_sign_verify_detached($signature, implode("\n", $lines), $publicKey) ? 'OK' : 'INVALID_SIGNATURE';
    }

    /**
     * `JSON.stringify(JSON.parse($json))` as V8 does it: every object lists the
     * keys that are array indices (canonical integers 0 … 2^32-2) first, in
     * ascending order, then the other keys in insertion order. Written here
     * independently of the SDK and pinned to Node's output by
     * PaymentLinkClientTest::testTheStandInReserialisesLikeV8().
     */
    public static function v8Reserialize(string $json): string
    {
        return self::v8Encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /** @param mixed $value A json_decode() value (objects as stdClass). */
    private static function v8Encode($value): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;
        if (is_array($value)) {
            return '[' . implode(',', array_map([self::class, 'v8Encode'], $value)) . ']';
        }
        if (!$value instanceof \stdClass) {
            return (string) json_encode($value, $flags);
        }
        $indices = [];
        $names = [];
        foreach (get_object_vars($value) as $key => $member) {
            $key = (string) $key;
            if (preg_match('/^(0|[1-9][0-9]*)$/', $key) === 1 && (float) $key <= 4294967294) {
                $indices[] = [$key, $member];
            } else {
                $names[] = [$key, $member];
            }
        }
        usort($indices, static fn (array $a, array $b): int => (int) $a[0] <=> (int) $b[0]);
        $members = [];
        foreach (array_merge($indices, $names) as [$key, $member]) {
            $members[] = json_encode($key, $flags) . ':' . self::v8Encode($member);
        }

        return '{' . implode(',', $members) . '}';
    }
}
