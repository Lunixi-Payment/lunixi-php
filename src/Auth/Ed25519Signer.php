<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Auth;

use Lunixi\Sdk\Exception\SignatureException;

/**
 * Ed25519 request signer (libsodium).
 *
 * Signs the canonical request string and returns a base64 signature that the
 * gateway verifies with the registered public key (crypto.util.verifySignature).
 * Accepts the merchant's unencrypted PKCS#8 Ed25519 private key (the PEM issued
 * by the Lunixi dashboard). Interop is plain Ed25519 over the UTF-8 canonical
 * string — identical to the Node `crypto.sign(null, data, {key, format:'pem',
 * type:'pkcs8'})` the gateway verifies against.
 *
 * The 32-byte seed is held in memory only and wiped on destruct.
 */
final class Ed25519Signer
{
    /** Unencrypted PKCS#8 Ed25519 DER prefix (RFC 8410): 16 bytes, then the 32-byte seed. */
    private const PKCS8_PREFIX_HEX = '302e020100300506032b657004220420';

    /** SubjectPublicKeyInfo DER prefix for an Ed25519 public key: 12 bytes, then the 32-byte key. */
    private const SPKI_PREFIX_HEX = '302a300506032b6570032100';

    /** @var string Raw 32-byte Ed25519 seed (private). */
    private string $seed;

    /**
     * @param string $privateKey Unencrypted PKCS#8 Ed25519 PEM (or raw 32/64-byte key material).
     * @throws SignatureException on an unsupported/malformed key.
     */
    public function __construct(string $privateKey)
    {
        $this->seed = self::extractSeed($privateKey);
    }

    public function __destruct()
    {
        if (isset($this->seed) && $this->seed !== '') {
            try {
                sodium_memzero($this->seed);
            } catch (\SodiumException $e) {
                // best-effort wipe
            }
        }
    }

    /**
     * Signs $message and returns the base64 signature (X-Signature header value).
     *
     * @throws SignatureException
     */
    public function sign(string $message): string
    {
        try {
            $keypair = sodium_crypto_sign_seed_keypair($this->seed);
            $secret = sodium_crypto_sign_secretkey($keypair);
            $signature = sodium_crypto_sign_detached($message, $secret);
            sodium_memzero($secret);
            sodium_memzero($keypair);

            return base64_encode($signature);
        } catch (\SodiumException $e) {
            throw new SignatureException('Ed25519 signing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns the corresponding public key as a SubjectPublicKeyInfo PEM
     * (what the gateway stores per `kid`). Useful for onboarding/key-registration
     * and for verifying signatures in tests.
     */
    public function publicKeyPem(): string
    {
        try {
            $keypair = sodium_crypto_sign_seed_keypair($this->seed);
            $publicKey = sodium_crypto_sign_publickey($keypair);
            sodium_memzero($keypair);
        } catch (\SodiumException $e) {
            throw new SignatureException('Could not derive public key: ' . $e->getMessage(), 0, $e);
        }

        return self::wrapPem('PUBLIC KEY', (string) hex2bin(self::SPKI_PREFIX_HEX) . $publicKey);
    }

    /**
     * Generates a fresh Ed25519 keypair as unencrypted PKCS#8 / SPKI PEMs.
     * Intended for onboarding (the plugin generates a keypair, registers the
     * public key with Lunixi, keeps the private key) and for tests.
     *
     * @return array{privateKey:string, publicKey:string}
     */
    public static function generateKeyPair(): array
    {
        try {
            $keypair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($keypair); // 64 bytes: seed || publicKey
            $seed = substr($secret, 0, 32);
            $publicKey = sodium_crypto_sign_publickey($keypair);
            sodium_memzero($secret);
            sodium_memzero($keypair);
        } catch (\SodiumException $e) {
            throw new SignatureException('Keypair generation failed: ' . $e->getMessage(), 0, $e);
        }

        return [
            'privateKey' => self::wrapPem('PRIVATE KEY', (string) hex2bin(self::PKCS8_PREFIX_HEX) . $seed),
            'publicKey' => self::wrapPem('PUBLIC KEY', (string) hex2bin(self::SPKI_PREFIX_HEX) . $publicKey),
        ];
    }

    /**
     * Extracts the raw 32-byte Ed25519 seed from a PKCS#8 PEM (preferred) or raw key material.
     */
    private static function extractSeed(string $privateKey): string
    {
        $der = self::decodeKeyMaterial($privateKey);
        $len = strlen($der);

        // Standard unencrypted PKCS#8 Ed25519: 48-byte DER = 16-byte prefix + 32-byte seed.
        $prefix = (string) hex2bin(self::PKCS8_PREFIX_HEX);
        if ($len === 48 && strncmp($der, $prefix, 16) === 0) {
            return substr($der, 16, 32);
        }

        // Tolerant: locate the seed after the Ed25519 OID (handles PKCS#8 with attributes/pubkey).
        $oid = (string) hex2bin('06032b6570'); // 1.3.101.112
        $oidPos = strpos($der, $oid);
        if ($oidPos !== false) {
            $marker = (string) hex2bin('04220420'); // OCTET STRING(34){ OCTET STRING(32) }
            $markerPos = strpos($der, $marker, $oidPos);
            if ($markerPos !== false) {
                $seed = substr($der, $markerPos + 4, 32);
                if (strlen($seed) === 32) {
                    return $seed;
                }
            }
        }

        // Raw seed (32) or raw libsodium secret key (64: seed || pub).
        if ($len === 32) {
            return $der;
        }
        if ($len === 64) {
            return substr($der, 0, 32);
        }

        throw new SignatureException(
            'Unsupported Ed25519 private key; expected an unencrypted PKCS#8 PEM as issued by the Lunixi dashboard.'
        );
    }

    /** Strips PEM armor (if any) and base64-decodes to DER/raw bytes. */
    private static function decodeKeyMaterial(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new SignatureException('Empty private key.');
        }

        if (strpos($trimmed, '-----BEGIN') !== false) {
            $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $trimmed);
            $der = $body !== null ? base64_decode($body, true) : false;
            if ($der === false) {
                throw new SignatureException('Private key PEM is not valid base64.');
            }
            return $der;
        }

        // No PEM armor: accept base64 of DER/raw, else raw bytes as-is.
        $decoded = base64_decode($trimmed, true);
        return $decoded !== false ? $decoded : $input;
    }

    private static function wrapPem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }
}
