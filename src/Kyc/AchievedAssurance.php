<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * The achieved per-domain assurance snapshot (mirrors the kyc-service
 * AchievedAssuranceProfile). Each domain is an AssuranceLevel; `meetsProfile`
 * reflects the required profile (when one was configured).
 *
 * WordPress stores this whole map (not a single level) so a gate can require a
 * specific domain — e.g. identity >= SUBSTANTIAL — without a future migration.
 */
final class AchievedAssurance
{
    /** Canonical domain keys (the WP per-domain assurance map keys). */
    public const IDENTITY = 'identity_evidence_strength';
    public const DOCUMENT = 'document_authenticity_level';
    public const BIOMETRIC = 'possession_biometric_binding';
    public const AUTHORITATIVE = 'authoritative_source_level';
    public const ADDRESS = 'address_assurance';
    public const AML = 'aml_freshness';
    public const HUMAN_REVIEW = 'human_review_level';

    public const DOMAINS = [
        self::IDENTITY, self::DOCUMENT, self::BIOMETRIC,
        self::AUTHORITATIVE, self::ADDRESS, self::AML, self::HUMAN_REVIEW,
    ];

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data The `achieved` profile object. */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** Level for a domain (one of the DOMAINS constants), defaulting to NONE. */
    public function level(string $domain): string
    {
        $value = $this->data[$domain] ?? AssuranceLevel::NONE;
        return is_string($value) && $value !== '' ? $value : AssuranceLevel::NONE;
    }

    public function identity(): string
    {
        return $this->level(self::IDENTITY);
    }

    public function address(): string
    {
        return $this->level(self::ADDRESS);
    }

    public function aml(): string
    {
        return $this->level(self::AML);
    }

    public function meetsProfile(): bool
    {
        return (bool) ($this->data['meetsProfile'] ?? false);
    }

    /** @return string[] Auditor-facing limitations (domains not performed / below requirement). */
    public function limitations(): array
    {
        $limits = $this->data['limitations'] ?? [];
        return is_array($limits) ? array_values(array_map('strval', $limits)) : [];
    }

    /**
     * The per-domain map WordPress persists (domain key => level).
     *
     * @return array<string,string>
     */
    public function toMap(): array
    {
        $map = [];
        foreach (self::DOMAINS as $domain) {
            $map[$domain] = $this->level($domain);
        }
        return $map;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
