<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Builds the body for `POST /api/v1/sanction/screening/name`. Required: name.
 * Add as much identity context as you have for a sharper result.
 *
 *   $req = (new ScreenNameRequest('Ayla Kurgu'))
 *       ->withCountry('TR')->withSubjectType(SubjectType::PERSON)
 *       ->withRiskDomains([RiskDomain::SANCTIONS, RiskDomain::PEP, RiskDomain::RCA])
 *       ->withCustomerReference((string) $wpUserId);
 *
 * Without `withRiskDomains()` and `withMode()` every risk domain is screened.
 * `mode` is a preset set of domains; sent with `riskDomains`, their
 * intersection is screened (an empty intersection is a 400).
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

    /**
     * Narrows the screening to these risk domains ({@see RiskDomain}). An
     * unknown domain is refused here: it is never dropped, because a domain
     * nobody screened would read as "no match".
     *
     * @param string[] $riskDomains
     */
    public function withRiskDomains(array $riskDomains): self
    {
        $domains = array_values(array_unique($riskDomains));
        if ($domains === []) {
            throw new ConfigurationException('riskDomains must not be empty; leave it out to screen every risk domain.');
        }
        foreach ($domains as $domain) {
            if (!is_string($domain) || !in_array($domain, RiskDomain::ALL, true)) {
                throw new ConfigurationException('Unsupported risk domain ' . json_encode($domain) . '.');
            }
        }
        $this->optional['riskDomains'] = $domains;
        return $this;
    }

    /**
     * Minimum match score. Matches score 0–1; the gateway also reads a value
     * above 1 as a percentage (85 = 0.85).
     */
    public function withThreshold(float $threshold): self
    {
        if ($threshold < 0 || $threshold > 100) {
            throw new ConfigurationException('threshold must be between 0 and 1 (or 0 and 100).');
        }
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
