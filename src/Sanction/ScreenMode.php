<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * Screening depth (mirrors the sanction-service screening modes).
 */
final class ScreenMode
{
    public const SANCTIONS_ONLY = 'sanctions_only';
    public const SANCTIONS_PEP = 'sanctions_pep';
    public const FULL_RISK = 'full_risk';

    public const ALL = [self::SANCTIONS_ONLY, self::SANCTIONS_PEP, self::FULL_RISK];

    private function __construct()
    {
    }
}
