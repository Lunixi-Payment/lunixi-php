<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Kinds and statuses of a bank transfer exception
 * (`GET /payments/bank-transfer/exceptions`).
 */
final class BankTransferException
{
    /** Arrived after the deadline (within 7 days). */
    public const KIND_LATE = 'LATE';
    /** The deadline passed with less than expected; the payment waits for your decision. */
    public const KIND_UNDERPAID = 'UNDERPAID';
    /** More than expected arrived; refund the excess. */
    public const KIND_OVERPAID = 'OVERPAID';

    public const STATUS_PENDING_DECISION = 'PENDING_DECISION';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REFUND_PENDING = 'REFUND_PENDING';
    public const STATUS_REFUNDED = 'REFUNDED';

    public const STATUSES = [
        self::STATUS_PENDING_DECISION, self::STATUS_ACCEPTED, self::STATUS_REFUND_PENDING, self::STATUS_REFUNDED,
    ];

    public const ENVIRONMENTS = ['TEST', 'LIVE'];

    /** Most items one list call returns. */
    public const LIMIT_MAX = 200;

    private function __construct()
    {
    }
}
