<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * `status` of the signed form POST your `callbackUrl` receives when a split
 * payment concludes on the continuation page (`completion.outcome`). The POST
 * carries `paymentId` (main payment), `orderId` (when set on the intent),
 * `status`, `success` ('true'/'false'), `code` (the split status), `message`,
 * `_timestamp` and `_signature` — the same fields and signature as the
 * single-card 3D Secure return. Informational: confirm with the
 * `payment.captured` webhook or getPayment(). Same values as the Node SDK's
 * `SplitCompletionOutcome`.
 */
final class SplitCompletionOutcome
{
    /** COMPLETED. */
    public const SUCCESS = 'success';
    /** VOIDED, FAILED or COMPENSATING. */
    public const FAILURE = 'failure';
    /** RECONCILIATION_REQUIRED. */
    public const PENDING = 'pending';

    private function __construct()
    {
    }
}
