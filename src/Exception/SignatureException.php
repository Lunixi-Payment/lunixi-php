<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * Ed25519 request signing failed — usually a malformed/unsupported private key
 * (must be an unencrypted PKCS#8 Ed25519 PEM) or a libsodium error.
 */
class SignatureException extends LunixiException
{
}
