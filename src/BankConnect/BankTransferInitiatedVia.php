<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Where a transfer or an exchange order was started.
 */
final class BankTransferInitiatedVia
{
    public const PANEL = 'PANEL';
    public const API = 'API';

    public const ALL = [
        self::PANEL,
        self::API,
    ];

    private function __construct()
    {
    }
}
