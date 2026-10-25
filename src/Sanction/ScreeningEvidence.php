<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * The list-publication evidence a screening carries: which publication it
 * read, how much of the requested scope it covered and the id of its
 * immutable evidence record. Used by the results of the screenings that read
 * the active publication (name, wallet, transaction, procurement ban).
 *
 * A replay of a screening made before list publications carries `null` for
 * all four; such a result never reads as clear.
 *
 * @internal
 */
trait ScreeningEvidence
{
    /** @var array<string,mixed> */
    private array $data;

    public function publicationId(): ?string
    {
        return SanctionValues::nullableString($this->data, 'publicationId');
    }

    public function publicationRevision(): ?int
    {
        return SanctionValues::nullableInt($this->data, 'publicationRevision');
    }

    /** {@see CoverageStatus} value, or null when the answer carries none. */
    public function coverageStatus(): ?string
    {
        return SanctionValues::nullableString($this->data, 'coverageStatus');
    }

    public function isCoverageComplete(): bool
    {
        return $this->coverageStatus() === CoverageStatus::COMPLETE;
    }

    /** Id of the screening's immutable evidence record (audits, disputes). */
    public function evidenceRef(): ?string
    {
        return SanctionValues::nullableString($this->data, 'evidenceRef');
    }

    /** @return array<string,mixed> The unwrapped `data` of the answer. */
    public function raw(): array
    {
        return $this->data;
    }
}
