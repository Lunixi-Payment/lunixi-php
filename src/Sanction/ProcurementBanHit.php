<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * A debarment (public procurement ban) hit.
 */
final class ProcurementBanHit
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

    /** {@see HitType} */
    public function hitType(): string
    {
        return SanctionValues::string($this->data, 'hitType');
    }

    public function entityName(): string
    {
        return SanctionValues::string($this->data, 'entityName');
    }

    public function entityType(): string
    {
        return SanctionValues::string($this->data, 'entityType');
    }

    /** What the match rests on: tckn | vkn | identifier | normalized_name | name_fuzzy. */
    public function matchBasis(): string
    {
        return SanctionValues::string($this->data, 'matchBasis');
    }

    public function activeBan(): bool
    {
        return ($this->data['activeBan'] ?? false) === true;
    }

    public function banScope(): string
    {
        return SanctionValues::string($this->data, 'banScope');
    }

    public function banReason(): string
    {
        return SanctionValues::string($this->data, 'banReason');
    }

    public function banStartDate(): string
    {
        return SanctionValues::string($this->data, 'banStartDate');
    }

    public function banEndDate(): string
    {
        return SanctionValues::string($this->data, 'banEndDate');
    }

    public function riskScore(): float
    {
        return (float) ($this->data['riskScore'] ?? 0);
    }

    public function sourceListName(): string
    {
        return SanctionValues::string($this->data, 'sourceListName');
    }

    /** @return SanctionMatchList[] */
    public function lists(): array
    {
        return SanctionValues::matchLists($this->data);
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
