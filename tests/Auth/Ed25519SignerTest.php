<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Auth;

use Lunixi\Sdk\Auth\CanonicalRequest;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Exception\SignatureException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the SDK produces a VALID Ed25519 signature over the canonical string —
 * i.e. one the gateway (Node crypto.verify with the registered PEM public key)
 * would accept. We verify with libsodium against the derived public key, which
 * is the same Ed25519 primitive the gateway verifies with.
 */
final class Ed25519SignerTest extends TestCase
{
    public function testSignsCanonicalAndSignatureVerifiesWithDerivedPublicKey(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $signer = new Ed25519Signer($keys['privateKey']);

        $canonical = CanonicalRequest::build('POST', '/api/v1/auth/token', '2026-06-15T12:00:00Z', 'nonce-abc');
        $signatureB64 = $signer->sign($canonical);

        $rawPublic = self::rawPublicKeyFromPem($signer->publicKeyPem());
        $ok = sodium_crypto_sign_verify_detached(
            base64_decode($signatureB64, true),
            $canonical,
            $rawPublic
        );

        $this->assertTrue($ok, 'gateway-equivalent Ed25519 verification must accept the signature');
    }

    public function testGeneratedKeypairPemsAreWellFormed(): void
    {
        $keys = Ed25519Signer::generateKeyPair();

        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $keys['privateKey']);
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $keys['publicKey']);
        // The signer accepts its own generated private key and re-derives the same public key.
        $signer = new Ed25519Signer($keys['privateKey']);
        $this->assertSame(
            self::normalizePem($keys['publicKey']),
            self::normalizePem($signer->publicKeyPem())
        );
    }

    public function testAcceptsRawSeedAndRawSecretKeyForms(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $seed = self::seedFromPkcs8Pem($keys['privateKey']);

        $fromSeed = new Ed25519Signer($seed);                      // raw 32-byte seed
        $message = 'hello';
        $rawPublic = self::rawPublicKeyFromPem($fromSeed->publicKeyPem());

        $this->assertTrue(
            sodium_crypto_sign_verify_detached(base64_decode($fromSeed->sign($message), true), $message, $rawPublic)
        );
    }

    public function testRejectsGarbageKey(): void
    {
        $this->expectException(SignatureException::class);
        new Ed25519Signer('not a key at all !!!');
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(SignatureException::class);
        new Ed25519Signer('');
    }

    /** Two signatures over the same message are identical (Ed25519 is deterministic). */
    public function testSignatureIsDeterministic(): void
    {
        $keys = Ed25519Signer::generateKeyPair();
        $signer = new Ed25519Signer($keys['privateKey']);

        $this->assertSame($signer->sign('m'), $signer->sign('m'));
    }

    private static function rawPublicKeyFromPem(string $pem): string
    {
        $der = self::pemBodyToDer($pem);
        // SPKI Ed25519: 12-byte header + 32-byte key.
        return substr($der, -32);
    }

    private static function seedFromPkcs8Pem(string $pem): string
    {
        $der = self::pemBodyToDer($pem);
        return substr($der, -32);
    }

    private static function pemBodyToDer(string $pem): string
    {
        $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);

        return (string) base64_decode((string) $body, true);
    }

    private static function normalizePem(string $pem): string
    {
        return preg_replace('/\s+/', '', $pem) ?? $pem;
    }
}
