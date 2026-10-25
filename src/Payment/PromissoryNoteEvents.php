<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Webhook event types of a promissory note. The envelope is the one of every
 * `payment.*` event (a `payment.*` subscription receives them); read the note
 * with PromissoryNoteEvent::fromEvent(). Statuses are polled from the
 * provider, so an event arrives some time after the due date, not at once.
 */
final class PromissoryNoteEvents
{
    public const PAID = 'payment.promissory_note.paid';
    public const PROTESTED = 'payment.promissory_note.protested';
    public const CANCELLED = 'payment.promissory_note.cancelled';

    public const ALL = [self::PAID, self::PROTESTED, self::CANCELLED];

    private function __construct()
    {
    }
}
