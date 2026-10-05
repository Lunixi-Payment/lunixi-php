<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Type of a wallet end user's account.
 */
final class WalletAccountType
{
    public const PERSONAL = 'PERSONAL';
    public const BUSINESS = 'BUSINESS';

    public const ALL = [
        self::PERSONAL,
        self::BUSINESS,
    ];

    private function __construct()
    {
    }
}
