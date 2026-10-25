<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/** A policy in the `data` of an insurance webhook. */
final class InsuranceWebhookPolicy extends InsuranceResource
{
    public function policyId(): string
    {
        return $this->str('policyId');
    }

    public function basketItemId(): string
    {
        return $this->str('basketItemId');
    }

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

    /**
     * The Lunixi API address of the policy document while the policy is in
     * force, otherwise null. Downloading it is a signed API call:
     * InsuranceClient::getPolicyDocument($policy->policyId()).
     */
    public function documentUrl(): ?string
    {
        return $this->strOrNull('documentUrl');
    }
}
