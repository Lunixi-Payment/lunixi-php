<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * The terms of a quote option the buyer accepts. Send both values back
 * unchanged in the order's consent (InsuranceClient::consentFromQuote()).
 */
final class InsuranceTerms extends InsuranceResource
{
    public function version(): string
    {
        return $this->str('version');
    }

    /** SHA-256 of the terms, 64 lowercase hexadecimal characters. */
    public function sha256(): string
    {
        return $this->str('sha256');
    }
}
