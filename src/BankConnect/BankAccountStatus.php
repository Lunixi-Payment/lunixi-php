<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Status of a connected bank account.
 */
final class BankAccountStatus
{
    public const ACTIVE = 'ACTIVE';
    public const INACTIVE = 'INACTIVE';

    public const ALL = [
        self::ACTIVE,
        self::INACTIVE,
    ];

    private function __construct()
    {
    }
}
