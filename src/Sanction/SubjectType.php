<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * The kind of entity being screened.
 */
final class SubjectType
{
    public const PERSON = 'person';
    public const ORGANIZATION = 'organization';

    public const ALL = [self::PERSON, self::ORGANIZATION];

    private function __construct()
    {
    }
}
