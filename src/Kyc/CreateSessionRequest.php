<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Builds the body for `POST /api/v1/kyc/sessions`.
 *
 * Required: subjectId + sessionType. Set externalCustomerId to the merchant's own
 * user id (echoed on every webhook for reconciliation). Identity is optional —
 * supply it (`withPerson`/`withCompany` + customerMode) or let the flow collect it.
 *
 *   $req = (new CreateSessionRequest($wpUserId, SessionType::KYC))
 *       ->withExternalCustomerId((string) $wpUserId)
 *       ->withJurisdiction('TR');
 */
final class CreateSessionRequest
{
    private string $subjectId;
    private string $sessionType;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(string $subjectId, string $sessionType)
    {
        if (trim($subjectId) === '') {
            throw new ConfigurationException("CreateSessionRequest 'subjectId' is required.");
        }
        $sessionType = strtoupper($sessionType);
        if (!in_array($sessionType, SessionType::ALL, true)) {
            throw new ConfigurationException("Unsupported sessionType '{$sessionType}'.");
        }
        $this->subjectId = $subjectId;
        $this->sessionType = $sessionType;
    }

    public function withExternalCustomerId(string $externalCustomerId): self
    {
        $this->optional['externalCustomerId'] = $externalCustomerId;
        return $this;
    }

    /** provided | collect | anonymous */
    public function withCustomerMode(string $mode): self
    {
        $this->optional['customerMode'] = $mode;
        return $this;
    }

    /** @param array<string,mixed> $person PERSON fields (firstName, lastName, email, dob, nationalId, …). */
    public function withPerson(array $person): self
    {
        $this->optional['customer'] = array_merge(['customerType' => 'PERSON'], $person);
        return $this;
    }

    /** @param array<string,mixed> $company COMPANY fields (companyName, registrationNumber, registrationCountry). */
    public function withCompany(array $company): self
    {
        $this->optional['customer'] = array_merge(['customerType' => 'COMPANY'], $company);
        return $this;
    }

    public function withJurisdiction(string $jurisdiction): self
    {
        $this->optional['jurisdiction'] = $jurisdiction;
        return $this;
    }

    /** @param string[] $documentTypes */
    public function withRequiredDocumentTypes(array $documentTypes): self
    {
        $this->optional['requiredDocumentTypes'] = array_values(array_map('strval', $documentTypes));
        return $this;
    }

    public function withPrimaryDocumentType(string $documentType): self
    {
        $this->optional['primaryDocumentType'] = $documentType;
        return $this;
    }

    public function withEvidencePurpose(string $purpose): self
    {
        $this->optional['evidencePurpose'] = $purpose;
        return $this;
    }

    public function withTtlMinutes(int $minutes): self
    {
        $this->optional['ttlMinutes'] = max(1, min(1440, $minutes));
        return $this;
    }

    public function withIdempotencyKey(string $key): self
    {
        $this->optional['idempotencyKey'] = $key;
        return $this;
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->optional['idempotencyKey'] ?? null;
        return (is_string($key) && $key !== '') ? $key : null;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->optional['evidenceContextJson'] = (string) json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge([
            'subjectId' => $this->subjectId,
            'sessionType' => $this->sessionType,
        ], $this->optional);
    }
}
