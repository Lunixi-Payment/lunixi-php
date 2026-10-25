<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * One Lunixi reference code (listReferenceValues()), sent as the value of an
 * insurance type field that takes a code (district, device or vehicle brand
 * and model, product category …).
 */
final class InsuranceReferenceValue extends InsuranceResource
{
    public function code(): string
    {
        return $this->str('code');
    }

    /** @return array<string,string> Text per language code. */
    public function label(): array
    {
        return $this->localized('label');
    }

    /** Province of a district, brand of a model; null for a top-level code. */
    public function parentCode(): ?string
    {
        return $this->strOrNull('parentCode');
    }
}
