<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Builds the body for `POST /api/v1/sanction/screening/name`. Required: name.
 * Add as much identity context as you have for a sharper result.
 *
 *   $req = (new ScreenNameRequest('John Smith'))
 *       ->withCountry('TR')->withSubjectType(SubjectType::PERSON)
 *       ->withMode(ScreenMode::SANCTIONS_PEP)
 *       ->withCustomerReference((string) $wpUserId);
 */
final class ScreenNameRequest
{
    private string $name;

    /** @var array<string,mixed> */
    private array $optional = [];

    /** @var array<int,array{type:string,value:string}> */
    private array $identifiers = [];

    public function __construct(string $name)
    {
        if (trim($name) === '') {
            throw new ConfigurationException("ScreenNameRequest 'name' is required.");
        }
        $this->name = $name;
    }

    public function withCountry(string $country): self
    {
        $this->optional['country'] = $country;
        return $this;
    }

    public function withDateOfBirth(string $dob): self
    {
        $this->optional['dateOfBirth'] = $dob;
        return $this;
    }

    public function withSubjectType(string $subjectType): self
    {
        if (!in_array($subjectType, SubjectType::ALL, true)) {
            throw new ConfigurationException("Unsupported subjectType '{$subjectType}'.");
        }
        $this->optional['subjectType'] = $subjectType;
        return $this;
    }

    public function withMode(string $mode): self
    {
        if (!in_array($mode, ScreenMode::ALL, true)) {
            throw new ConfigurationException("Unsupported screening mode '{$mode}'.");
        }
        $this->optional['mode'] = $mode;
        return $this;
    }

    /** Match threshold (0–100). */
    public function withThreshold(float $threshold): self
    {
        $this->optional['threshold'] = $threshold;
        return $this;
    }

    /** Merchant's own reference (e.g. WP user id) for reconciliation. */
    public function withCustomerReference(string $reference): self
    {
        $this->optional['customerReference'] = $reference;
        return $this;
    }

    public function withIdentifier(string $type, string $value): self
    {
        if ($type !== '' && $value !== '') {
            $this->identifiers[] = ['type' => $type, 'value' => $value];
        }
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $body = array_merge(['name' => $this->name], $this->optional);
        if ($this->identifiers !== []) {
            $body['identifiers'] = $this->identifiers;
        }
        return $body;
    }
}
