<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * `paymentStatus` values. A timeout is not a failure: an expired result window
 * makes the payment UNKNOWN, not DECLINED.
 */
final class PhysicalPosPaymentStatus
{
    /** Recorded; the device has not started yet. */
    public const NOT_STARTED = 'NOT_STARTED';
    /** The device started: waiting for the card or the bank. */
    public const IN_PROGRESS = 'IN_PROGRESS';
    /** Money taken (final). On a child: the refund / void / close happened. */
    public const APPROVED = 'APPROVED';
    /** Money taken, less than requested (approvedAmountMinor). */
    public const PARTIALLY_APPROVED = 'PARTIALLY_APPROVED';
    /** Bank or card declined; no money taken. */
    public const DECLINED = 'DECLINED';
    /** Started, then cancelled; no money taken. */
    public const CANCELLED = 'CANCELLED';
    /** Never started: timed out, or cancelled before it reached the device. */
    public const EXPIRED = 'EXPIRED';
    /** Never started: an error (the device rejected the command …). */
    public const FAILED = 'FAILED';
    /** Outcome unknown — money MAY have been taken. Do not charge again. */
    public const UNKNOWN = 'UNKNOWN';
    /** Automatic verification did not conclude; resolve it in the panel. */
    public const MANUAL_REVIEW = 'MANUAL_REVIEW';

    public const ALL = [
        self::NOT_STARTED, self::IN_PROGRESS, self::APPROVED, self::PARTIALLY_APPROVED, self::DECLINED,
        self::CANCELLED, self::EXPIRED, self::FAILED, self::UNKNOWN, self::MANUAL_REVIEW,
    ];

    /** Final statuses. */
    public const FINAL_STATUSES = [
        self::APPROVED, self::PARTIALLY_APPROVED, self::DECLINED, self::CANCELLED, self::EXPIRED, self::FAILED,
    ];

    private function __construct()
    {
    }
}
