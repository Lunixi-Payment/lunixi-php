<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Status of an outgoing transfer.
 *
 * PENDING_OTP: started in the dashboard, waiting for the user's e-mail code
 * (an API-key transfer never has it). SUBMITTED: approved and queued or sent
 * to the bank. PROCESSING: the bank is processing it. COMPLETED: done; it can
 * still become RETURNED if the receiving bank sends it back. FAILED, RETURNED
 * and CANCELED are final.
 */
final class BankTransferStatus
{
    public const PENDING_OTP = 'PENDING_OTP';
    public const SUBMITTED = 'SUBMITTED';
    public const PROCESSING = 'PROCESSING';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';
    public const RETURNED = 'RETURNED';
    public const CANCELED = 'CANCELED';

    public const ALL = [
        self::PENDING_OTP,
        self::SUBMITTED,
        self::PROCESSING,
        self::COMPLETED,
        self::FAILED,
        self::RETURNED,
        self::CANCELED,
    ];

    private function __construct()
    {
    }
}
