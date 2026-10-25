<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\Http\Envelope;

/**
 * A wallet-screening result (`POST /api/v1/sanction/screening/wallet`).
 *
 * Two kinds of hit, kept apart:
 *   - {@see publicationHits()}: listed parties of the active list publication
 *     that hold the address (exact address match);
 *   - {@see hits()}: your own wallet list, exposure records and crypto
 *     intelligence. List-publication hits are never in this field.
 */
final class WalletScreenResult
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

    public function address(): string
    {
        return SanctionValues::string($this->data, 'address');
    }

    public function chain(): string
    {
        return SanctionValues::string($this->data, 'chain');
    }

    /** True when a party in the sanctions domain holds the address. */
    public function isSanctioned(): bool
    {
        return ($this->data['isSanctioned'] ?? false) === true;
    }

    public function riskScore(): float
    {
        return (float) ($this->data['riskScore'] ?? 0);
    }

    public function riskLevel(): string
    {
        return SanctionValues::string($this->data, 'riskLevel');
    }

    /** @return WalletPublicationHit[] */
    public function publicationHits(): array
    {
        return array_map(
            static fn (array $row): WalletPublicationHit => new WalletPublicationHit($row),
            SanctionValues::rows($this->data, 'publicationHits')
        );
    }

    /**
     * @return array<int,array<string,mixed>> Your own list, exposure and
     *   crypto-intelligence hits: hitId, hitType, riskCategory, score,
     *   hopDistance, taintPercentage, explanation.
     */
    public function hits(): array
    {
        return SanctionValues::rows($this->data, 'hits');
    }

    public function isHit(): bool
    {
        return $this->isSanctioned() || $this->publicationHits() !== [] || $this->hits() !== [];
    }

    /** Nothing found and complete coverage. */
    public function isClear(): bool
    {
        return $this->success() && !$this->isHit() && $this->isCoverageComplete();
    }
}
