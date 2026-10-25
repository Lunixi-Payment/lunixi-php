<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Status of a currency exchange order. An order is created SUBMITTED.
 */
final class BankFxOrderStatus
{
    public const SUBMITTED = 'SUBMITTED';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';

    public const ALL = [
        self::SUBMITTED,
        self::COMPLETED,
        self::FAILED,
    ];

    private function __construct()
    {
    }
}
