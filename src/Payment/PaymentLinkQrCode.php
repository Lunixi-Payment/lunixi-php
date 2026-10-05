<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A payment link's QR code image. The code encodes the link URL
 * (`https://pay.lunixi.com/<shortCode>`), so a printed code keeps working as
 * long as the link does.
 */
final class PaymentLinkQrCode
{
    private string $contentType;

    private string $bytes;

    public function __construct(string $contentType, string $bytes)
    {
        $this->contentType = $contentType;
        $this->bytes = $bytes;
    }

    /** `image/svg+xml` or `image/png`. */
    public function contentType(): string
    {
        return $this->contentType;
    }

    /** The raw image bytes (SVG markup for `svg`). */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /** File extension matching the content type. */
    public function extension(): string
    {
        return strpos($this->contentType, 'png') !== false ? 'png' : 'svg';
    }
}
