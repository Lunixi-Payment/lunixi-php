<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Policy axis (`policyStatus`) of an order and of each of its policies.
 * A policy is in force while ISSUED or CANCEL_PENDING.
 */
final class InsurancePolicyStatus
{
    public const NOT_REQUESTED = 'NOT_REQUESTED';
    public const PENDING = 'PENDING';
    public const PARTIALLY_ISSUED = 'PARTIALLY_ISSUED';
    public const ISSUED = 'ISSUED';
    public const FAILED_RETRYABLE = 'FAILED_RETRYABLE';
    public const FAILED_FINAL = 'FAILED_FINAL';
    public const UNKNOWN = 'UNKNOWN';
    public const CANCEL_PENDING = 'CANCEL_PENDING';
    public const CANCELLED = 'CANCELLED';

    public const ALL = [
        self::NOT_REQUESTED,
        self::PENDING,
        self::PARTIALLY_ISSUED,
        self::ISSUED,
        self::FAILED_RETRYABLE,
        self::FAILED_FINAL,
        self::UNKNOWN,
        self::CANCEL_PENDING,
        self::CANCELLED,
    ];

    private function __construct()
    {
    }
}
