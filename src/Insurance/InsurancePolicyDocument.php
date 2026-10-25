<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * A policy document (getPolicyDocument()). Lunixi does not store it: it is
 * read from the insurance provider on every call.
 */
final class InsurancePolicyDocument
{
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
