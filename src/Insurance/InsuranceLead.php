<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * A lead for an insurance type sold by request only (InsuranceClient::createLead()).
 * It has no premium, order, policy or document. The provider's name, code and
 * reference are not part of it.
 */
final class InsuranceLead extends InsuranceResource
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

    /** Lunixi error code (`insurance.<area>.<reason>`) of a FAILED lead. */
    public function errorCode(): ?string
    {
        return $this->strOrNull('errorCode');
    }

    /**
     * The lead as sent, with the personal fields and the note masked (`***`).
     *
     * @return array<string,mixed>
     */
    public function requestMasked(): array
    {
        return $this->object('requestMasked');
    }

    public function submittedAt(): ?string
    {
        return $this->strOrNull('submittedAt');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }

    public function updatedAt(): string
    {
        return $this->str('updatedAt');
    }
}
