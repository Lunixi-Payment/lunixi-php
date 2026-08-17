<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * KYC/KYB session status (mirrors the kyc-service session-status enum).
 */
final class SessionStatus
{
    // Active / in-flight
    public const CREATED = 'CREATED';
    public const INITIATED = 'INITIATED';
    public const DOCUMENT_SUBMITTED = 'DOCUMENT_SUBMITTED';
    public const PROCESSING = 'PROCESSING';
    public const AWAITING_PROVIDER_CALLBACK = 'AWAITING_PROVIDER_CALLBACK';
    public const AWAITING_ADDITIONAL_DOCUMENT = 'AWAITING_ADDITIONAL_DOCUMENT';
    public const AWAITING_USER_ACTION = 'AWAITING_USER_ACTION';
    public const UNDER_REVIEW = 'UNDER_REVIEW';
    public const PENDING_ADDITIONAL_INFO = 'PENDING_ADDITIONAL_INFO';
    public const RESCREEN_REQUIRED = 'RESCREEN_REQUIRED';
    public const REOPENED = 'REOPENED';

    // Terminal
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const MANUAL_REVIEW = 'MANUAL_REVIEW';
    public const EXPIRED = 'EXPIRED';
    public const ABANDONED = 'ABANDONED';
    public const CANCELLED = 'CANCELLED';

    public const TERMINAL_STATES = [
        self::APPROVED, self::REJECTED, self::MANUAL_REVIEW,
        self::EXPIRED, self::ABANDONED, self::CANCELLED,
    ];

    public static function isTerminal(string $status): bool
    {
        return in_array(strtoupper($status), self::TERMINAL_STATES, true);
    }

    private function __construct()
    {
    }
}
