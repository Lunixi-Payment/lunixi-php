<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Status of a bank connection.
 *
 * Data is read only while a connection is ACTIVE or DEGRADED (DEGRADED: the
 * last reads from the bank failed, so the data may be older; see the
 * account's freshness). requestSync() on any other status is
 * 409 `bank_connect.connection.not_active`.
 */
final class BankConnectionStatus
{
    public const PENDING_DOCUMENTS = 'PENDING_DOCUMENTS';
    public const UNDER_REVIEW = 'UNDER_REVIEW';
    public const PENDING_SETUP = 'PENDING_SETUP';
    public const ACTIVE = 'ACTIVE';
    public const DEGRADED = 'DEGRADED';
    public const SUSPENDED = 'SUSPENDED';
    public const DISCONNECTED = 'DISCONNECTED';

    public const ALL = [
        self::PENDING_DOCUMENTS,
        self::UNDER_REVIEW,
        self::PENDING_SETUP,
        self::ACTIVE,
        self::DEGRADED,
        self::SUSPENDED,
        self::DISCONNECTED,
    ];

    private function __construct()
    {
    }
}
