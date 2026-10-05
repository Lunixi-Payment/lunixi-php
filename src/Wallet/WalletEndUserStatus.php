<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Status of a wallet end user. FROZEN blocks money movement; CLOSED is final.
 */
final class WalletEndUserStatus
{
    public const ACTIVE = 'ACTIVE';
    public const FROZEN = 'FROZEN';
    public const CLOSED = 'CLOSED';

    public const ALL = [
        self::ACTIVE,
        self::FROZEN,
        self::CLOSED,
    ];

    private function __construct()
    {
    }
}
