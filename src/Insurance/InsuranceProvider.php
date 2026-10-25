<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * The insurance provider connection behind a quote option, an order, a
 * reconciliation group or a webhook. Informational: `code` is Lunixi's
 * catalogue code of the provider, `connectionId` your connection to it.
 */
final class InsuranceProvider extends InsuranceResource
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

    public function connectionId(): string
    {
        return $this->str('connectionId');
    }
}
