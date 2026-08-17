<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * Generic screening outcome for the non-name screening modes (wallet, account,
 * transaction, adverse-media, public-procurement-ban). These share a
 * risk/decision shape but use mode-specific flags (isSanctioned vs isBlacklisted
 * vs matched vs a decision string), so this model normalises them behind one
 * `isHit()` while still exposing the raw fields.
 *
 * (Name screening keeps its richer {@see ScreenResult} with typed matches.)
 */
final class ScreenOutcome
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $response */
    public function __construct(array $response)
    {
        $this->data = $response;
    }

    public function success(): bool
    {
        return (bool) ($this->data['success'] ?? false);
    }

    public function requestId(): string
    {
        return (string) ($this->data['requestId'] ?? '');
    }

    public function riskScore(): float
    {
        return (float) ($this->data['riskScore'] ?? 0);
    }

    public function riskLevel(): string
    {
        return (string) ($this->data['riskLevel'] ?? '');
    }

    /** Decision string where the mode returns one (transaction / adverse-media). */
    public function decision(): string
    {
        return (string) ($this->data['decision'] ?? '');
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
                return array_values($this->data[$key]);
            }
        }
        return [];
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

    public function isClear(): bool
    {
        return !$this->isHit();
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
