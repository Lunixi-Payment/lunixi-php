<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * Base class for every exception thrown by the Lunixi SDK.
 *
 * Catch this to handle any SDK error generically; catch a subclass for a
 * specific failure mode (configuration, signing, auth, transport/API, webhook).
 */
class LunixiException extends \Exception
{
}
