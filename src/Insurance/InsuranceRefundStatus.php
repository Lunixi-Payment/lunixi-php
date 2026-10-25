<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Premium refund axis of an order (`refundStatus`). An open refund is
 * REQUIRED, PENDING or FAILED.
 */
final class InsuranceRefundStatus
{
    public const NONE = 'NONE';
    public const REQUIRED = 'REQUIRED';
    public const PENDING = 'PENDING';
    public const REFUNDED = 'REFUNDED';
    public const FAILED = 'FAILED';

    public const ALL = [
        self::NONE,
        self::REQUIRED,
        self::PENDING,
        self::REFUNDED,
        self::FAILED,
    ];

    private function __construct()
    {
    }
}
