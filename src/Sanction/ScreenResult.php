<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\Http\Envelope;

/**
 * A name-screening result (`POST /api/v1/sanction/screening/name`). The
 * screening fields are under the envelope's `data`.
 *
 * Screening returns matches, not verdicts. {@see isClear()} is true only for a
 * successful screening with no match AND complete coverage; a `PARTIAL` or
 * `DEGRADED` screening, or a replay without coverage, is not clear even with
 * no match. A screening that could not run (503) throws before a result is
 * built.
 */
final class ScreenResult
{
    use ScreeningEvidence;

    /** @var SanctionMatch[] */
    private array $matches;

    private string $status;

    private string $requestId;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $this->data = Envelope::data($response);
        $this->status = SanctionValues::string($response, 'status');
        $this->requestId = SanctionValues::string($response, 'requestId');
        $this->matches = array_map(
            static fn (array $row): SanctionMatch => new SanctionMatch($row),
            SanctionValues::rows($this->data, 'matches')
        );
    }

    /** True when the envelope reports `status: "success"`. */
    public function success(): bool
    {
        return $this->status === 'success';
    }

    public function hasMatches(): bool
    {
        return $this->matches !== [];
    }

    /**
     * Nothing found: a successful screening, no match, and every requested
     * risk domain screened (`coverageStatus` COMPLETE).
     */
    public function isClear(): bool
    {
        return $this->success() && $this->matches === [] && $this->isCoverageComplete();
    }

    /** @return SanctionMatch[] */
    public function matches(): array
    {
        return $this->matches;
    }

    /**
     * The screening's id: use it for screening history and report requests.
     * Null on an answer that does not carry one.
     */
    public function screeningRequestId(): ?string
    {
        return SanctionValues::nullableString($this->data, 'screeningRequestId');
    }

    /**
     * The HTTP request's id (the envelope's `requestId`), for support. It is
     * NOT the screening's id; see {@see screeningRequestId()}.
     */
    public function requestId(): string
    {
        return $this->requestId;
    }

    public function latencyMs(): int
    {
        return (int) ($this->data['latencyMs'] ?? 0);
    }

    /** @return string[] The risk domains requested. */
    public function screenedRiskDomains(): array
    {
        return SanctionValues::stringList($this->data, 'screenedRiskDomains');
    }

    /** @return string[] Requested risk domains no list in the publication covers. */
    public function uncoveredRiskDomains(): array
    {
        return SanctionValues::stringList($this->data, 'uncoveredRiskDomains');
    }

    /**
     * Which vintage of data was screened. Empty when the freshness manifest
     * could not be produced: word a compliance statement conservatively then.
     *
     * @return array<string,mixed>
     */
    public function datasetFreshness(): array
    {
        return SanctionValues::json($this->data, 'datasetFreshness', 'datasetFreshnessJson');
    }

    /** Highest match score (0.0 when there is no match). */
    public function topScore(): float
    {
        $top = 0.0;
        foreach ($this->matches as $match) {
            $top = max($top, $match->score());
        }
        return $top;
    }
}
