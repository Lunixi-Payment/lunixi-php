<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

/** Authenticated encryption for platform-stored credentials and checkout tokens. */
final class SecretBox
{
    private string $key;
    public function __construct(string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Supply a separate 32-byte server-side encryption key.');
        }
        $this->key = $key;
    }
    public function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->key));
    }
    public function decrypt(string $value): string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Encrypted value is invalid.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if ($plain === false) { throw new \RuntimeException('Encrypted value authentication failed.'); }
        return $plain;
    }
}
