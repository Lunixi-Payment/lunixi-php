<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Transfer webhook event types (product BANK_CONNECT), delivered in the same
 * signed envelope as every Lunixi webhook and verified with WebhookVerifier.
 * Read the payload with BankTransferEvent. One event per transfer and type;
 * the event id is the same on every redelivery: dedupe on it.
 */
final class BankTransferEvents
{
    /** A transfer was created (API: SUBMITTED; dashboard: PENDING_OTP). */
    public const CREATED = 'bank_transfer.created';

    /** The bank completed the transfer. */
    public const COMPLETED = 'bank_transfer.completed';

    /** The transfer failed; `failureCode` says why. */
    public const FAILED = 'bank_transfer.failed';

    /** The receiving bank sent a completed transfer back. */
    public const RETURNED = 'bank_transfer.returned';

    /** Subscription pattern covering every transfer event. */
    public const ALL_EVENTS = 'bank_transfer.*';

    public const ALL = [
        self::CREATED,
        self::COMPLETED,
        self::FAILED,
        self::RETURNED,
    ];

    private function __construct()
    {
    }
}
