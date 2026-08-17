<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Identity;

/**
 * The result of an identity live-session analysis (`live-sessions/analyze[-sync]`).
 *
 * This is the device/identity SIGNAL surface — it does NOT carry an allow/review/
 * block verdict (that lives in fraud-service; reach it via `includeFraudDecision`
 * or the fraud client). What it exposes: the `traceId` handle (used to query
 * account-links), the analysis status, the device/identity risk signals
 * (`riskFlags`, `reasonCodes`, similarity/actor probabilities) and the
 * decision-grade snapshot (`identitySnapshot`).
 */
final class IdentityDecision
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data The response `data` object. */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** Handle to query account-links / related-sessions / timeline. */
    public function traceId(): string
    {
        return (string) ($this->data['traceId'] ?? '');
    }

    /** queued | processing | completed | failed */
    public function analysisStatus(): string
    {
        return (string) ($this->data['analysisStatus'] ?? '');
    }

    /** Risk signals raised by the analysis (e.g. same_device_cluster, tor_exit, …). */
    public function riskFlags(): array
    {
        $rows = $this->data['riskFlags'] ?? [];
        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    /** Machine-readable reason codes for the signals. */
    public function reasonCodes(): array
    {
        $rows = $this->data['reasonCodes'] ?? [];
        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    public function hasRiskFlags(): bool
    {
        return $this->riskFlags() !== [];
    }

    /** Probability that this is the SAME actor as a linked session (0..1). */
    public function sameActorProbability(): float
    {
        return (float) ($this->data['sameActorProbability'] ?? 0);
    }

    /** Probability that this is the same physical device (0..1). */
    public function sameDeviceProbability(): float
    {
        return (float) ($this->data['sameDeviceProbability'] ?? 0);
    }

    public function confidenceScore(): float
    {
        return (float) ($this->data['confidenceScore'] ?? 0);
    }

    /** Whether the produced snapshot is decision-grade (sufficient for a downstream decision). */
    public function decisionGrade(): bool
    {
        $snapshot = $this->snapshot();
        if (array_key_exists('decisionGrade', $snapshot)) {
            return (bool) $snapshot['decisionGrade'];
        }
        // Back-compat fallback for older/flat shapes.
        return (bool) ($this->data['decisionGrade'] ?? false);
    }

    /**
     * The full identity decision snapshot (`identitySnapshot`): snapshotId, version,
     * subjectType/subjectId, decisionGrade, computedAt, expiresAt, …
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        foreach (['identitySnapshot', 'snapshot'] as $key) {
            if (isset($this->data[$key]) && is_array($this->data[$key])) {
                return $this->data[$key];
            }
        }
        return [];
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
