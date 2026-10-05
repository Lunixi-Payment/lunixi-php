<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Status of a corporate account.
 */
final class WalletCorporateAccountStatus
{
    public const ACTIVE = 'ACTIVE';
    public const ARCHIVED = 'ARCHIVED';

    public const ALL = [
        self::ACTIVE,
        self::ARCHIVED,
    ];

    private function __construct()
    {
    }
}
