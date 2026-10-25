<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/** Per-note result of PaymentClient::cancelPromissoryNotes() (`cancelResults[].result`). */
final class PromissoryNoteCancelResult
{
    public const CANCELLED = 'CANCELLED';
    /** The note was already cancelled. */
    public const ALREADY_CLOSED = 'ALREADY_CLOSED';
    /** The note was collected or protested; it cannot be cancelled. */
    public const NOT_CANCELLABLE = 'NOT_CANCELLABLE';
    /** The provider did not cancel the note; `errorCode` tells whether to try again. */
    public const FAILED = 'FAILED';

    public const ALL = [self::CANCELLED, self::ALREADY_CLOSED, self::NOT_CANCELLABLE, self::FAILED];

    /*
     * `errorCode` of a result: always a Lunixi code, never the provider's; null
     * for CANCELLED and ALREADY_CLOSED.
     */

    /** NOT_CANCELLABLE: the note was collected or the provider does not cancel it. */
    public const ERROR_NOT_CANCELLABLE = 'payment.promissory_note.not_cancellable';
    /** FAILED, temporary: the provider could not be reached; cancel again later. */
    public const ERROR_PROVIDER_UNAVAILABLE = 'payment.promissory_note.provider_unavailable';
    /** FAILED, permanent: the provider refused the cancellation. */
    public const ERROR_CANCEL_REJECTED = 'payment.promissory_note.cancel_rejected';

    public const ERROR_CODES = [self::ERROR_NOT_CANCELLABLE, self::ERROR_PROVIDER_UNAVAILABLE, self::ERROR_CANCEL_REJECTED];

    private function __construct()
    {
    }
}
