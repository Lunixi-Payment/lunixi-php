<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * How closely a match fits the screened subject.
 */
final class HitType
{
    public const EXACT = 'exact';
    public const PROBABLE = 'probable';
    public const POSSIBLE = 'possible';

    public const ALL = [self::EXACT, self::PROBABLE, self::POSSIBLE];

    private function __construct()
    {
    }
}
