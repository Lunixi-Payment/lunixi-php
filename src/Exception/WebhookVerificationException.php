<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * An inbound webhook failed verification (bad/missing signature, timestamp
 * outside tolerance, or malformed body). The caller MUST reject the webhook
 * (do NOT act on an unverified payload — fail-closed).
 */
class WebhookVerificationException extends LunixiException
{
}
