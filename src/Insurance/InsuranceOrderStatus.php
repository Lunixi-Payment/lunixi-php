<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * The overall status of an insurance order (`status`). It is not stored:
 * every read derives it from the order's status axes (`collectionStatus`,
 * `policyStatus`, `refundStatus`), in this order — REFUNDED, CANCELLED,
 * FAILED, ACTION_REQUIRED, PROCESSING, PARTIALLY_ACTIVE, ACTIVE, CREATED.
 */
final class InsuranceOrderStatus
{
    public const CREATED = 'CREATED';
    public const PROCESSING = 'PROCESSING';
    public const ACTION_REQUIRED = 'ACTION_REQUIRED';
    public const PARTIALLY_ACTIVE = 'PARTIALLY_ACTIVE';
    public const ACTIVE = 'ACTIVE';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';
    public const REFUNDED = 'REFUNDED';

    public const ALL = [
        self::CREATED,
        self::PROCESSING,
        self::ACTION_REQUIRED,
        self::PARTIALLY_ACTIVE,
        self::ACTIVE,
        self::FAILED,
        self::CANCELLED,
        self::REFUNDED,
    ];

    private function __construct()
    {
    }
}
