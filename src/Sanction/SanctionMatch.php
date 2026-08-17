<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * A single sanction/watchlist hit from a screening response.
 */
final class SanctionMatch
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function hitId(): string
    {
        return (string) ($this->data['hitId'] ?? '');
    }

    public function matchedName(): string
    {
        return (string) ($this->data['matchedName'] ?? '');
    }

    /** Source list, e.g. OFAC / UN / EU / multi_source. */
    public function listCode(): string
    {
        return (string) ($this->data['listCode'] ?? '');
    }

    public function recordType(): string
    {
        return (string) ($this->data['recordType'] ?? '');
    }

    /** 0.0–1.0 confidence. */
    public function score(): float
    {
        return (float) ($this->data['score'] ?? 0);
    }

    /** exact | probable | possible */
    public function hitType(): string
    {
        return (string) ($this->data['hitType'] ?? '');
    }

    public function reason(): string
    {
        return (string) ($this->data['reason'] ?? '');
    }

    /** @return array<string,mixed> Parsed match factors, if present. */
    public function explanation(): array
    {
        $exp = $this->data['explanation'] ?? null;
        if (is_array($exp)) {
            return $exp;
        }
        $json = $this->data['explanationJson'] ?? null;
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
