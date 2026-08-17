<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * Honest assurance ladder (mirrors the kyc-service AssuranceLevel, rank-aligned
 * with NIST 800-63 IAL / eIDAS LoA). A gate passes when achieved >= required.
 */
final class AssuranceLevel
{
    public const NONE = 'NONE';
    public const NOT_PERFORMED = 'NOT_PERFORMED';
    public const LOW = 'LOW';
    public const SUBSTANTIAL = 'SUBSTANTIAL';
    public const HIGH = 'HIGH';

    /** Numeric rank on the honest ladder (NONE/NOT_PERFORMED = 0 … HIGH = 3). */
    public static function rank(string $level): int
    {
        switch (strtoupper($level)) {
            case self::HIGH:
                return 3;
            case self::SUBSTANTIAL:
                return 2;
            case self::LOW:
                return 1;
            default:
                return 0; // NONE / NOT_PERFORMED / unknown
        }
    }

    /** True iff $achieved meets or exceeds $required. */
    public static function meets(string $achieved, string $required): bool
    {
        return self::rank($achieved) >= self::rank($required);
    }

    private function __construct()
    {
    }
}
