<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * Invalid or missing SDK configuration (base URL, key id, private key, …).
 * Thrown before any network call — a programming/setup error, not a runtime fault.
 */
class ConfigurationException extends LunixiException
{
}
