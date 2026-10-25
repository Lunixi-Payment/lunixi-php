<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Status of a split payment (`status` of PaymentClient::createSplitPayment()
 * and getSplitPayment()).
 */
final class SplitPaymentStatus
{
    public const COMPOSING = 'COMPOSING';
    public const AUTHORIZING = 'AUTHORIZING';
    /** A card leg waits for the buyer's 3D Secure step (pendingStep()->redirectPath()). */
    public const AWAITING_3DS = 'AWAITING_3DS';
    /** A non-card leg waits for a step (pendingStep()). */
    public const AWAITING_ACTION = 'AWAITING_ACTION';
    /** A credit leg waits for the lender's approval; no money has moved. */
    public const AWAITING_CREDIT_APPROVAL = 'AWAITING_CREDIT_APPROVAL';
    /** A card capture failed after the non-reversible leg; a new card is asked for the shortfall. */
    public const AWAITING_REPLACEMENT_CARD = 'AWAITING_REPLACEMENT_CARD';
    public const AUTHORIZED = 'AUTHORIZED';
    public const CAPTURING = 'CAPTURING';
    /** Every leg was charged: the order is paid. */
    public const COMPLETED = 'COMPLETED';
    /** A leg failed: holds are released and charged legs are refunded. */
    public const COMPENSATING = 'COMPENSATING';
    /** Failed without a charge left on any leg. */
    public const VOIDED = 'VOIDED';
    public const FAILED = 'FAILED';
    /** The non-reversible leg was charged but the rest could not be collected; you decide on delivery. */
    public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

    /** Every status (filter values of PaymentClient::listSplitPayments()). */
    public const ALL = [
        self::COMPOSING,
        self::AUTHORIZING,
        self::AWAITING_3DS,
        self::AWAITING_ACTION,
        self::AWAITING_CREDIT_APPROVAL,
        self::AWAITING_REPLACEMENT_CARD,
        self::AUTHORIZED,
        self::CAPTURING,
        self::COMPLETED,
        self::COMPENSATING,
        self::VOIDED,
        self::FAILED,
        self::RECONCILIATION_REQUIRED,
    ];

    /**
     * The split payment is still running (waiting for a step or being
     * processed). PaymentClient::createSplitPayment() returns these answers
     * instead of throwing.
     */
    public const IN_PROGRESS = [
        self::COMPOSING,
        self::AUTHORIZING,
        self::AWAITING_3DS,
        self::AWAITING_ACTION,
        self::AWAITING_CREDIT_APPROVAL,
        self::AWAITING_REPLACEMENT_CARD,
        self::AUTHORIZED,
        self::CAPTURING,
    ];

    private function __construct()
    {
    }
}
