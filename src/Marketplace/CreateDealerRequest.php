<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Marketplace;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Builds the body for `POST /api/v1/marketplace/dealers` — registers a sub-dealer
 * (a vendor/seller who receives split funds). The dealer then self-onboards
 * (KYC + IBAN + contract) via the onboarding portal before going ACTIVE.
 */
final class CreateDealerRequest
{
    public const TYPE_LEGAL_ENTITY = 'LEGAL_ENTITY';
    public const TYPE_INDIVIDUAL = 'INDIVIDUAL';

    private string $legalName;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(string $legalName)
    {
        if (trim($legalName) === '') {
            throw new ConfigurationException("CreateDealerRequest 'legalName' is required.");
        }
        $this->legalName = $legalName;
    }

    public function withTaxId(string $taxId): self
    {
        $this->optional['taxId'] = $taxId;
        return $this;
    }

    public function withDealerType(string $dealerType): self
    {
        $this->optional['dealerType'] = $dealerType;
        return $this;
    }

    public function withCategoryCode(string $categoryCode): self
    {
        $this->optional['categoryCode'] = $categoryCode;
        return $this;
    }

    public function withEmail(string $email): self
    {
        $this->optional['email'] = $email;
        return $this;
    }

    /** The merchant's own reference (e.g. the WP vendor user id) for reconciliation. */
    public function withExternalRef(string $externalRef): self
    {
        $this->optional['externalRef'] = $externalRef;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge(['legalName' => $this->legalName], $this->optional);
    }
}
