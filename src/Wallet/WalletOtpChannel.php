<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Channel the one-time password of a money movement is sent over.
 */
final class WalletOtpChannel
{
    public const EMAIL = 'EMAIL';
    public const SMS = 'SMS';
    public const PUSH = 'PUSH';

    public const ALL = [
        self::EMAIL,
        self::SMS,
        self::PUSH,
    ];

    private function __construct()
    {
    }
}
