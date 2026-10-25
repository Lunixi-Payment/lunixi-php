<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Answer of BankConnectClient::requestSync(): QUEUED (the read starts now or
 * is already waiting) or SCHEDULED (the minimum interval has not passed; the
 * read runs at `scheduledAt`).
 */
final class BankSyncStatus
{
    public const QUEUED = 'QUEUED';
    public const SCHEDULED = 'SCHEDULED';

    public const ALL = [
        self::QUEUED,
        self::SCHEDULED,
    ];

    private function __construct()
    {
    }
}
