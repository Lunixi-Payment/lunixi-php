<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * Subscription invoice lifecycle status (mirrors the subscription-service
 * InvoiceStatus enum). DRAFT → OPEN → (PAID | UNCOLLECTIBLE | VOID).
 */
final class InvoiceStatus
{
    public const DRAFT = 'DRAFT';                 // not yet finalised
    public const OPEN = 'OPEN';                   // finalised; ready to charge
    public const PAID = 'PAID';                   // charge succeeded
    public const UNCOLLECTIBLE = 'UNCOLLECTIBLE'; // dunning exhausted; gave up
    public const VOID = 'VOID';                   // manually voided / cancelled

    private function __construct()
    {
    }
}
