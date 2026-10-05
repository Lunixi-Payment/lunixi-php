<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * The gateway rejected the credentials / token exchange (401/403, invalid
 * signature, expired or revoked key). Distinct from a transport ApiException
 * so callers can prompt the merchant to re-check their API credentials.
 */
class AuthenticationException extends LunixiException
{
}
