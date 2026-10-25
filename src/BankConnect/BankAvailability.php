<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Whether a bank of the catalogue can be connected.
 *
 * AVAILABLE: it can be connected (connections are started in the Lunixi
 * dashboard). COMING_SOON: not yet.
 */
final class BankAvailability
{
    public const AVAILABLE = 'AVAILABLE';
    public const COMING_SOON = 'COMING_SOON';

    public const ALL = [
        self::AVAILABLE,
        self::COMING_SOON,
    ];

    private function __construct()
    {
    }
}
