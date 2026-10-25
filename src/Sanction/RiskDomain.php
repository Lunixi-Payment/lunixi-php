<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * Risk domains a screening can be narrowed to (`riskDomains`). Omitted, every
 * domain is screened. Same set as the Node SDK's `SanctionRiskDomain`.
 */
final class RiskDomain
{
    public const SANCTIONS = 'sanctions';
    public const PEP = 'pep';
    public const RCA = 'rca';
    public const WANTED = 'wanted';
    public const REGULATORY = 'regulatory';
    public const DEBARMENT = 'debarment';
    public const OWNERSHIP = 'ownership';
    public const VESSEL = 'vessel';
    public const SECURITIES = 'securities';

    public const ALL = [
        self::SANCTIONS, self::PEP, self::RCA, self::WANTED, self::REGULATORY,
        self::DEBARMENT, self::OWNERSHIP, self::VESSEL, self::SECURITIES,
    ];

    private function __construct()
    {
    }
}
