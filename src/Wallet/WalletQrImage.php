<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * A wallet QR code as an image ({@see WalletQr::image()}).
 */
final class WalletQrImage
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
        return strpos($this->contentType, 'png') !== false ? WalletQrImageFormat::PNG : WalletQrImageFormat::SVG;
    }
}
