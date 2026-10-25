<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Status of a lead (`status`): what Lunixi knows about sending it to the
 * insurance provider. There is no later step: the provider does not report
 * back on a lead.
 *
 * - PENDING: the provider call is still running.
 * - SUBMITTED: the provider received the lead (`insurance.lead.submitted`).
 * - UNKNOWN: the request reached the provider but the outcome is not known. It
 *   is never sent again; open a new lead with a new Idempotency-Key if needed.
 * - FAILED: the provider refused it or it never reached the provider (`errorCode`).
 */
final class InsuranceLeadStatus
{
    public const PENDING = 'PENDING';
    public const SUBMITTED = 'SUBMITTED';
    public const UNKNOWN = 'UNKNOWN';
    public const FAILED = 'FAILED';

    public const ALL = [
        self::PENDING,
        self::SUBMITTED,
        self::UNKNOWN,
        self::FAILED,
    ];

    private function __construct()
    {
    }
}
