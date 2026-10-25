<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * The lead in an `insurance.lead.*` webhook (InsuranceLeadWebhookData::lead()).
 * No personal data, no note and no provider reference.
 */
final class InsuranceWebhookLead extends InsuranceResource
{
    /** `insl_…`. */
    public function leadId(): string
    {
        return $this->str('leadId');
    }

    public function insuranceType(): string
    {
        return $this->str('insuranceType');
    }

    public function schemaVersion(): int
    {
        return $this->int('schemaVersion');
    }

    /** One of the InsuranceLeadStatus constants. */
    public function status(): string
    {
        return $this->str('status');
    }

    public function submittedAt(): ?string
    {
        return $this->strOrNull('submittedAt');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }
}
