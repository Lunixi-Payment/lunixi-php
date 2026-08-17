<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Marketplace;

/**
 * Sub-dealer ("altbayi") lifecycle status (mirrors the marketplace-service
 * SubDealer status). Only ACTIVE dealers are eligible for split payments.
 */
final class DealerStatus
{
    public const DRAFT = 'DRAFT';
    public const PENDING_KYC = 'PENDING_KYC';
    public const ACTIVE = 'ACTIVE';
    public const SUSPENDED = 'SUSPENDED';
    public const HOLD = 'HOLD';
    public const CLOSED = 'CLOSED';

    private function __construct()
    {
    }
}
