<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Premium collection axis of an order (`collectionStatus`).
 */
final class InsuranceCollectionStatus
{
    public const NOT_STARTED = 'NOT_STARTED';
    public const PENDING = 'PENDING';
    public const ACTION_REQUIRED = 'ACTION_REQUIRED';
    public const COLLECTED = 'COLLECTED';
    public const FAILED = 'FAILED';
    public const UNKNOWN = 'UNKNOWN';

    public const ALL = [
        self::NOT_STARTED,
        self::PENDING,
        self::ACTION_REQUIRED,
        self::COLLECTED,
        self::FAILED,
        self::UNKNOWN,
    ];

    private function __construct()
    {
    }
}
