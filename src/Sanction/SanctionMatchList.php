<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * One list a match (or a wallet / procurement hit) appears on. A person or
 * organization on several lists is one match with one entry here per list.
 */
final class SanctionMatchList
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** e.g. `OFAC`, `UN`, `EU_FSF`, `TR_MASAK`. */
    public function datasetCode(): string
    {
        return SanctionValues::string($this->data, 'datasetCode');
    }

    public function displayName(): string
    {
        return SanctionValues::string($this->data, 'displayName');
    }

    /** e.g. `SANCTIONS`. */
    public function datasetKind(): string
    {
        return SanctionValues::string($this->data, 'datasetKind');
    }

    /** The list version the screening read. */
    public function datasetVersionId(): string
    {
        return SanctionValues::string($this->data, 'datasetVersionId');
    }

    /** @return string[] */
    public function subLists(): array
    {
        return SanctionValues::stringList($this->data, 'subLists');
    }

    /** @return string[] */
    public function programs(): array
    {
        return SanctionValues::stringList($this->data, 'programs');
    }

    /** @return string[] Records of this list merged into the match. */
    public function entityIds(): array
    {
        return SanctionValues::stringList($this->data, 'entityIds');
    }

    /**
     * @return array<int,array<string,mixed>> Listing records: subList, label,
     *   program, reference, listedOn.
     */
    public function listings(): array
    {
        return SanctionValues::rows($this->data, 'listings');
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
