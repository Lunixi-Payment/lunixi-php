<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Canonical payment intent status values (mirrors the gateway PaymentIntentStatus enum).
 */
final class PaymentStatus
{
    public const INITIATED = 'INITIATED';
    public const PROCESSING = 'PROCESSING';
    public const AWAITING_3D = 'AWAITING_3D';
    public const AUTHORIZED = 'AUTHORIZED';
    public const CAPTURED = 'CAPTURED';
    public const PARTIALLY_CAPTURED = 'PARTIALLY_CAPTURED';
    public const REFUNDED = 'REFUNDED';
    public const PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    public const VOIDED = 'VOIDED';
    public const FAILED = 'FAILED';
    public const EXPIRED = 'EXPIRED';
    public const NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /** Terminal-success states (money captured). */
    public const CAPTURED_STATES = [self::CAPTURED, self::PARTIALLY_CAPTURED];

    /** States where the buyer's funds have NOT been captured (no settlement owed). */
    public const FINAL_FAILURE_STATES = [self::FAILED, self::VOIDED, self::EXPIRED];

    private function __construct()
    {
    }
}
