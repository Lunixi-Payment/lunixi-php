<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * A cursor-paginated list of KYC sessions (GET /api/v1/kyc/sessions).
 */
final class KycSessionList
{
    /** @var KycSession[] */
    private array $items;
    private ?string $nextPageToken;

    /** @param array<string,mixed> $response The full list envelope. */
    public function __construct(array $response)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->items = array_map(
            static fn ($row): KycSession => new KycSession(is_array($row) ? $row : []),
            array_values($data)
        );
        $token = $response['nextPageToken'] ?? null;
        $this->nextPageToken = (is_string($token) && $token !== '') ? $token : null;
    }

    /** @return KycSession[] */
    public function items(): array
    {
        return $this->items;
    }

    public function nextPageToken(): ?string
    {
        return $this->nextPageToken;
    }

    public function hasMore(): bool
    {
        return $this->nextPageToken !== null;
    }
}
