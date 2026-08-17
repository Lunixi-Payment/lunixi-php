<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * Catalogue of fraud webhook event types delivered to a merchant endpoint (same
 * signed envelope as payment/subscription webhooks). The `data` payload carries
 * the decision (traceId, paymentId, decision, score, reasonCodes, tags, …).
 */
final class FraudEvents
{
    public const DECISION_COMPLETED = 'fraud.decision.completed';
    public const SHADOW_REQUESTED = 'fraud.shadow.requested';
    public const SHADOW_COMPLETED = 'fraud.shadow.completed';

    private function __construct()
    {
    }
}
