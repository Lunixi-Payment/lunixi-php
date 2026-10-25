<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Webhook event types of a split payment. The result of the main payment
 * arrives as `payment.captured` / `payment.failed` with `settlementMode: SPLIT`
 * and `legs[]`; deliver the order on that event.
 */
final class SplitPaymentEvents
{
    public const COMPLETED = 'payment.split.completed';
    public const VOIDED = 'payment.split.voided';
    public const COMPENSATING = 'payment.split.compensating';
    /** The non-reversible leg's result is being confirmed; card holds are kept until then. */
    public const OUTCOME_PENDING = 'payment.split.outcome_pending';
    /** The order could not be fully collected (`shortfallAmountMinor`, `reason`). */
    public const RECONCILIATION_REQUIRED = 'payment.split.reconciliation_required';
    /** Leg event: a charged leg is being refunded and retried until it succeeds. */
    public const LEG_REFUND_PENDING = 'payment.split.leg_refund_pending';

    /**
     * Put this key into a webhook endpoint's event types to also receive the
     * events of each leg. It is not an event type and `payment.*` does not
     * include it.
     */
    public const LEG_EVENTS_SUBSCRIPTION = 'payment.split.leg_events';

    /** `reason` values of `payment.split.reconciliation_required`. */
    public const RECONCILIATION_REASONS = [
        'REPLACEMENT_CARD_FAILED',
        'REPLACEMENT_WINDOW_EXPIRED',
        'BUYER_ABANDONED',
        'CAPTURE_NOT_RECOVERABLE',
    ];

    private function __construct()
    {
    }
}
