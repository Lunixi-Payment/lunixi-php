<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * A fraud decision (response `data` of evaluate / a persisted decision log).
 * Read-only typed accessors over the engine's verified output; `get()`/`raw()`
 * expose the rest (explainability tree, live signals, actions, …).
 */
final class FraudDecision
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data The response `data` object. */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** One of the FraudDecisionValue::* constants. */
    public function decision(): string
    {
        return (string) ($this->data['decision'] ?? '');
    }

    /** Risk score (higher = riskier). */
    public function score(): float
    {
        return (float) ($this->data['score'] ?? 0);
    }

    public function traceId(): string
    {
        return (string) ($this->data['traceId'] ?? '');
    }

    public function paymentId(): string
    {
        return (string) ($this->data['paymentId'] ?? '');
    }

    /** @return string[] Reason codes that contributed to the decision. */
    public function reasonCodes(): array
    {
        return self::stringList($this->data['reasonCodes'] ?? []);
    }

    /** @return string[] Ids of the rules that matched. */
    public function matchedRules(): array
    {
        return self::stringList($this->data['matchedRules'] ?? []);
    }

    /** @return string[] */
    public function matchedRuleGroups(): array
    {
        return self::stringList($this->data['matchedRuleGroups'] ?? []);
    }

    /** @return string[] Free-form tags attached to the decision. */
    public function tags(): array
    {
        return self::stringList($this->data['tags'] ?? []);
    }

    public function latencyMs(): int
    {
        return (int) ($this->data['latencyMs'] ?? 0);
    }

    /** Human-readable explainability summary, if present. */
    public function explainabilitySummary(): string
    {
        $exp = $this->data['explainability'] ?? null;
        if (is_array($exp) && isset($exp['summary'])) {
            return (string) $exp['summary'];
        }
        return '';
    }

    /** Persisted-log timestamp (decision logs), or null. */
    public function createdAt(): ?string
    {
        $value = $this->data['createdAt'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    public function isAllow(): bool
    {
        return $this->decision() === FraudDecisionValue::ALLOW;
    }

    public function isReview(): bool
    {
        return $this->decision() === FraudDecisionValue::REVIEW;
    }

    public function isBlock(): bool
    {
        return $this->decision() === FraudDecisionValue::BLOCK;
    }

    public function isForce3D(): bool
    {
        return $this->decision() === FraudDecisionValue::FORCE_3D;
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

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map('strval', $value));
    }
}
