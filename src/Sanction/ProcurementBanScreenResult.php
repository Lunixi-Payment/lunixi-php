<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\Http\Envelope;

/**
 * A public-procurement-ban screening result
 * (`POST /api/v1/sanction/intelligence/public-procurement-ban`): the
 * debarment lists of the active publication, by name and TCKN/VKN.
 *
 * The Turkish public procurement ban list (KİK) has no machine-readable
 * source and is not screened: {@see uncoveredSources()} names it
 * (`TR_PUBLIC_PROCUREMENT_BAN`, `NOT_PUBLISHED`) and coverage is `PARTIAL`, so
 * {@see isClear()} is false while it is uncovered. Check EKAP separately.
 */
final class ProcurementBanScreenResult
{
    use ScreeningEvidence;

    private string $status;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $this->data = Envelope::data($response);
        $this->status = SanctionValues::string($response, 'status');
    }

    public function success(): bool
    {
        return $this->status === 'success';
    }

    public function matched(): bool
    {
        return ($this->data['matched'] ?? false) === true;
    }

    public function riskScore(): float
    {
        return (float) ($this->data['riskScore'] ?? 0);
    }

    public function decision(): string
    {
        return SanctionValues::string($this->data, 'decision');
    }

    /** @return ProcurementBanHit[] */
    public function hits(): array
    {
        return array_map(
            static fn (array $row): ProcurementBanHit => new ProcurementBanHit($row),
            SanctionValues::rows($this->data, 'hits')
        );
    }

    /** @return string[] */
    public function screenedRiskDomains(): array
    {
        return SanctionValues::stringList($this->data, 'screenedRiskDomains');
    }

    /**
     * @return array<int,array<string,mixed>> The debarment lists read:
     *   datasetCode, displayName, datasetVersionId.
     */
    public function searchedLists(): array
    {
        return SanctionValues::rows($this->data, 'searchedLists');
    }

    /**
     * @return array<int,array<string,mixed>> Sources expected but absent from
     *   the publication: datasetCode, displayName, reason.
     */
    public function uncoveredSources(): array
    {
        return SanctionValues::rows($this->data, 'uncoveredSources');
    }

    public function isHit(): bool
    {
        return $this->matched() || $this->hits() !== [];
    }

    /** Nothing found, complete coverage and no uncovered source. */
    public function isClear(): bool
    {
        return $this->success() && !$this->isHit() && $this->isCoverageComplete() && $this->uncoveredSources() === [];
    }
}
