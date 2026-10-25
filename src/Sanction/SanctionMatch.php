<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * A single (merged) match from a name screening. A person or organization on
 * several lists is ONE match: its score is the highest of the lists and every
 * list it appears on is in {@see lists()}.
 */
final class SanctionMatch
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function hitId(): string
    {
        return SanctionValues::string($this->data, 'hitId');
    }

    /** The matched record; pass it to the entity-profile and exposure calls. */
    public function entityId(): string
    {
        return SanctionValues::string($this->data, 'entityId');
    }

    public function matchedName(): string
    {
        return SanctionValues::string($this->data, 'matchedName');
    }

    /** List code of the representative record. Read {@see lists()} for all of them. */
    public function listCode(): string
    {
        return SanctionValues::string($this->data, 'listCode');
    }

    /** The list of the match's representative record. Read {@see lists()} for all of them. */
    public function sourceListName(): string
    {
        return SanctionValues::string($this->data, 'sourceListName');
    }

    public function recordType(): string
    {
        return SanctionValues::string($this->data, 'recordType');
    }

    /** 0.0–1.0 similarity; anything under the request's threshold is dropped. */
    public function score(): float
    {
        return (float) ($this->data['score'] ?? 0);
    }

    /** {@see HitType}: exact | probable | possible */
    public function hitType(): string
    {
        return SanctionValues::string($this->data, 'hitType');
    }

    public function reason(): string
    {
        return SanctionValues::string($this->data, 'reason');
    }

    /** @return string[] Risk domains of the matched party ({@see RiskDomain}). */
    public function riskDomains(): array
    {
        return SanctionValues::stringList($this->data, 'riskDomains');
    }

    /** @return SanctionMatchList[] Every list the match appears on. */
    public function lists(): array
    {
        return SanctionValues::matchLists($this->data);
    }

    /** @return array<string,mixed> Parsed match factors, if present. */
    public function explanation(): array
    {
        return SanctionValues::json($this->data, 'explanation', 'explanationJson');
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
