<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * A screening result. The gateway returns `matches` (+ requestId, latencyMs) at
 * the response ROOT, not under `data`.
 */
final class ScreenResult
{
    /** @var SanctionMatch[] */
    private array $matches;
    private string $requestId;
    private int $latencyMs;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $rows = isset($response['matches']) && is_array($response['matches']) ? $response['matches'] : [];
        $this->matches = array_map(
            static fn ($row): SanctionMatch => new SanctionMatch(is_array($row) ? $row : []),
            array_values($rows)
        );
        $this->requestId = (string) ($response['requestId'] ?? '');
        $this->latencyMs = (int) ($response['latencyMs'] ?? 0);
    }

    public function hasMatches(): bool
    {
        return $this->matches !== [];
    }

    public function isClear(): bool
    {
        return $this->matches === [];
    }

    /** @return SanctionMatch[] */
    public function matches(): array
    {
        return $this->matches;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }

    /** Highest hit confidence among the matches (0.0 when clear). */
    public function topScore(): float
    {
        $top = 0.0;
        foreach ($this->matches as $match) {
            $top = max($top, $match->score());
        }
        return $top;
    }
}
