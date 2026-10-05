<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for `PUT /api/v1/subscriptions/customers` (idempotent upsert keyed by
 * `externalId` — the merchant's own customer key, e.g. a WordPress user id).
 */
final class UpsertCustomerRequest
{
    private string $externalId;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(string $externalId)
    {
        if (trim($externalId) === '') {
            throw new ConfigurationException("UpsertCustomerRequest 'externalId' is required.");
        }
        $this->externalId = $externalId;
    }

    public function withEmail(string $email): self
    {
        $this->optional['email'] = $email;
        return $this;
    }

    public function withName(string $name): self
    {
        $this->optional['name'] = $name;
        return $this;
    }

    public function withPhone(string $phone): self
    {
        $this->optional['phone'] = $phone;
        return $this;
    }

    public function withLocale(string $locale): self
    {
        $this->optional['locale'] = $locale;
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->optional['metadata'] = $metadata;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge(['externalId' => $this->externalId], $this->optional);
    }
}
