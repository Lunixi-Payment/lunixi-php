<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * The printable notes of a payment as a PDF, one note per page
 * (PaymentClient::downloadPromissoryNoteDocument()). Built from the provider's
 * current data on request and not stored.
 */
final class PromissoryNoteDocument
{
    public const LOCALE_TR = 'tr-TR';
    public const LOCALE_EN = 'en-GB';

    public const LOCALES = [self::LOCALE_TR, self::LOCALE_EN];

    private string $contentType;

    private string $bytes;

    public function __construct(string $contentType, string $bytes)
    {
        $this->contentType = $contentType;
        $this->bytes = $bytes;
    }

    /** `application/pdf`. */
    public function contentType(): string
    {
        return $this->contentType;
    }

    /** The raw PDF bytes. */
    public function bytes(): string
    {
        return $this->bytes;
    }
}
