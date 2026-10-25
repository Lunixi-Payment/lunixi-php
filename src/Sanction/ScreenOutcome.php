<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\Http\Envelope;

/**
 * Screening outcome for account, transaction and adverse-media screening and
 * behavioural-alert evaluation. These share a risk/decision shape but use
 * mode-specific flags (isSanctioned vs isBlacklisted vs matched vs a decision
 * string), so this model normalises them behind one `isHit()` while still
 * exposing the raw fields. The fields are under the envelope's `data`.
 *
 * Transaction screening reads the active list publication and reports the
 * weakest coverage among its parties: it is clear only with `COMPLETE`
 * coverage (empty when no party was screened). Account screening reads your
 * own deny list and account risk data and reports no coverage.
 *
 * Name, wallet and procurement-ban screening have their own results
 * ({@see ScreenResult}, {@see WalletScreenResult},
 * {@see ProcurementBanScreenResult}).
 */
final class ScreenOutcome
{
    use ScreeningEvidence;

    private string $status;

    private string $requestId;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $this->data = Envelope::data($response);
        $this->status = SanctionValues::string($response, 'status');
        $this->requestId = SanctionValues::string($response, 'requestId');
    }

    /** True when the envelope reports `status: "success"`. */
    public function success(): bool
    {
        return $this->status === 'success';
    }

    /** The HTTP request's id (the envelope's `requestId`), for support. */
    public function requestId(): string
    {
        return $this->requestId;
    }

    public function riskScore(): float
    {
        return (float) ($this->data['riskScore'] ?? 0);
    }

    public function riskLevel(): string
    {
        return SanctionValues::string($this->data, 'riskLevel');
    }

    /** Decision string where the mode returns one (transaction / adverse-media). */
    public function decision(): string
    {
        return SanctionValues::string($this->data, 'decision');
    }

    /** Whether a manual case is required (transaction screening). */
    public function caseRequired(): bool
    {
        return (bool) ($this->data['caseRequired'] ?? false);
    }

    /** @return array<int,array<string,mixed>> The mode's hits (or name matches). */
    public function hits(): array
    {
        foreach (['hits', 'matches'] as $key) {
            if (isset($this->data[$key]) && is_array($this->data[$key])) {
                return SanctionValues::rows($this->data, $key);
            }
        }
        return [];
    }

    /**
     * Per-party evidence, coverage and list detail of a transaction screening.
     *
     * @return array<string,mixed>
     */
    public function results(): array
    {
        return SanctionValues::json($this->data, 'results', 'resultsJson');
    }

    /**
     * Normalised "is this a positive screen" across every mode: any sanction /
     * blacklist / adverse-media match flag, a case requirement, a blocking
     * decision, or a non-empty hit list.
     */
    public function isHit(): bool
    {
        if (!empty($this->data['isSanctioned']) || !empty($this->data['isBlacklisted']) || !empty($this->data['matched'])) {
            return true;
        }
        if ($this->caseRequired() || $this->hits() !== []) {
            return true;
        }
        $decision = strtolower($this->decision());
        return in_array($decision, ['block', 'blocked', 'reject', 'rejected', 'review', 'hit', 'escalate'], true);
    }

    /**
     * Nothing found. When the answer reports coverage (transaction screening),
     * it must also be `COMPLETE`.
     */
    public function isClear(): bool
    {
        if (!$this->success() || $this->isHit()) {
            return false;
        }

        return !array_key_exists('coverageStatus', $this->data) || $this->isCoverageComplete();
    }
}
