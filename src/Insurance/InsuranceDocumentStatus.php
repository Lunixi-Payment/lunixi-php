<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Policy document axis (`documentStatus`); it does not change the order's
 * overall status.
 */
final class InsuranceDocumentStatus
{
    public const NOT_AVAILABLE = 'NOT_AVAILABLE';
    public const PENDING = 'PENDING';
    public const AVAILABLE = 'AVAILABLE';
    public const FAILED = 'FAILED';

    public const ALL = [
        self::NOT_AVAILABLE,
        self::PENDING,
        self::AVAILABLE,
        self::FAILED,
    ];

    private function __construct()
    {
    }
}
