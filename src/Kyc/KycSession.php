<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * A KYC/KYB session (the create/get response payload). The gateway returns a
 * large payload `{ session: {...}, capturePolicy, verificationRequirements, … }`;
 * core fields are read from `session`, with policy/evidence exposed raw.
 *
 * NO PII is returned — only status, decision, risk, the achieved assurance
 * snapshot and an explanation.
 */
final class KycSession
{
    /** @var array<string,mixed> */
    private array $payload;
    /** @var array<string,mixed> */
    private array $session;

    /** @param array<string,mixed> $payload The response `data` object. */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->session = (isset($payload['session']) && is_array($payload['session'])) ? $payload['session'] : $payload;
    }

    public function sessionId(): string
    {
        return (string) ($this->session['sessionId'] ?? '');
    }

    /** One of the SessionStatus::* constants. */
    public function status(): string
    {
        return (string) ($this->session['status'] ?? '');
    }

    public function sessionType(): string
    {
        return (string) ($this->session['sessionType'] ?? '');
    }

    /** The merchant's gateway org id (needed by the browser SDK config). */
    public function merchantId(): string
    {
        return (string) ($this->session['merchantId'] ?? '');
    }

    /** The merchant's own customer id (= WP user id), echoed back. */
    public function externalCustomerId(): string
    {
        return (string) ($this->session['externalCustomerId'] ?? '');
    }

    public function jurisdiction(): string
    {
        return (string) ($this->session['jurisdiction'] ?? '');
    }

    /** One of DecisionValue::* (terminal sessions), or '' while in flight. */
    public function decision(): string
    {
        $d = $this->session['decision'] ?? null;
        if (is_array($d)) {
            return (string) ($d['finalDisposition'] ?? ($d['machineDecision'] ?? ''));
        }
        return '';
    }

    public function riskLevel(): string
    {
        $d = $this->session['decision'] ?? null;
        if (is_array($d) && isset($d['riskLevel'])) {
            return (string) $d['riskLevel'];
        }
        return (string) ($this->session['riskLevel'] ?? '');
    }

    public function explanationSummary(): string
    {
        $d = $this->session['decision'] ?? null;
        return is_array($d) ? (string) ($d['explanationSummary'] ?? '') : '';
    }

    /** The achieved per-domain assurance snapshot, or null if none yet. */
    public function assurance(): ?AchievedAssurance
    {
        $d = $this->session['decision'] ?? null;
        if (is_array($d) && isset($d['assuranceSnapshot']['achieved']) && is_array($d['assuranceSnapshot']['achieved'])) {
            return new AchievedAssurance($d['assuranceSnapshot']['achieved']);
        }
        return null;
    }

    public function expiresAt(): ?string
    {
        $value = $this->session['expiresAt'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    public function isApproved(): bool
    {
        return $this->status() === SessionStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status() === SessionStatus::REJECTED;
    }

    public function isTerminal(): bool
    {
        return SessionStatus::isTerminal($this->status());
    }

    /** @return array<string,mixed> The capture policy snapshot (SDK behaviour). */
    public function capturePolicy(): array
    {
        return isset($this->payload['capturePolicy']) && is_array($this->payload['capturePolicy']) ? $this->payload['capturePolicy'] : [];
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->session[$key] ?? ($this->payload[$key] ?? null);
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->payload;
    }
}
