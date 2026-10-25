<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * The insurance company behind a quote option, apart from the provider that
 * brokers it (InsuranceProvider). `code` is Lunixi's code of the company: the
 * same company from two providers has the same code.
 */
final class InsuranceUnderwriter extends InsuranceResource
{
    public function code(): string
    {
        return $this->str('code');
    }

    public function name(): string
    {
        return $this->str('name');
    }

    public function logoUrl(): ?string
    {
        return $this->strOrNull('logoUrl');
    }

    /** The company's own information text to show the buyer, if any. */
    public function disclosureText(): ?string
    {
        return $this->strOrNull('disclosureText');
    }
}
