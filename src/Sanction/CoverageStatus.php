<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * How much of the requested scope a screening covered.
 *
 *   COMPLETE  every requested risk domain was screened.
 *   PARTIAL   a requested domain has no list in the publication
 *             (`uncoveredRiskDomains` / `uncoveredSources`); no match is clear
 *             only for what was screened.
 *   DEGRADED  the candidate search was limited; similarly spelled names may
 *             have been missed. Run the screening again.
 */
final class CoverageStatus
{
    public const COMPLETE = 'COMPLETE';
    public const PARTIAL = 'PARTIAL';
    public const DEGRADED = 'DEGRADED';

    public const ALL = [self::COMPLETE, self::PARTIAL, self::DEGRADED];

    private function __construct()
    {
    }
}
