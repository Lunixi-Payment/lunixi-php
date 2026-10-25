<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/** A policy of an insurance order, one per covered basket item. */
final class InsuranceOrderPolicy extends InsuranceResource
{
    /** `insp_…`; download its document with InsuranceClient::getPolicyDocument(). */
    public function policyId(): string
    {
        return $this->str('policyId');
    }

    public function basketItemId(): string
    {
        return $this->str('basketItemId');
    }

    /** The provider's policy number; null until the policy is issued. */
    public function policyNumber(): ?string
    {
        return $this->strOrNull('policyNumber');
    }

    /** One of the InsurancePolicyStatus constants. */
    public function policyStatus(): string
    {
        return $this->str('policyStatus');
    }

    /** One of the InsuranceDocumentStatus constants. */
    public function documentStatus(): string
    {
        return $this->str('documentStatus');
    }
}
