<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * KYC session types. EVIDENCE is a first-class product (proof-of-X), not a KYC
 * detail — usable beyond identity onboarding.
 */
final class SessionType
{
    public const KYC = 'KYC';            // person identity
    public const KYB = 'KYB';            // company + UBO
    public const EVIDENCE = 'EVIDENCE';  // proof-of-address/funds/wealth/card/...

    public const ALL = [self::KYC, self::KYB, self::EVIDENCE];

    private function __construct()
    {
    }
}
