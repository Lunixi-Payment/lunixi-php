<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/** Status of one promissory note (`notes[].status`). */
final class PromissoryNoteStatus
{
    public const ISSUED = 'ISSUED';
    public const PAID = 'PAID';
    public const PROTESTED = 'PROTESTED';
    public const CANCELLED = 'CANCELLED';

    public const ALL = [self::ISSUED, self::PAID, self::PROTESTED, self::CANCELLED];

    private function __construct()
    {
    }
}
