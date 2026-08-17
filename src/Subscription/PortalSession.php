<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * An SDK portal session minted by the merchant backend for a browser embed
 * (`POST /api/v1/subscriptions/portal/sdk-session`). The proof fields are
 * forwarded to the browser, which presents them when initialising the Lunixi
 * portal SDK; the portal then drives the whole subscription screen (plans,
 * start, cards w/ 1-TL verification + 3DS, invoices, cancel/change).
 *
 * The gateway returns these fields at the response ROOT (spread with the OK
 * envelope), not under `data`.
 */
final class PortalSession
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        // Tolerate both root-level (current gateway) and data-wrapped shapes.
        $data = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : [];
        $this->data = array_merge($response, $data);
    }

    public function portalToken(): string
    {
        return (string) ($this->data['portalToken'] ?? '');
    }

    public function sessionProof(): string
    {
        return (string) ($this->data['sessionProof'] ?? '');
    }

    public function proofNonce(): string
    {
        return (string) ($this->data['proofNonce'] ?? ($this->data['nonce'] ?? ''));
    }

    public function proofExpiresAt(): ?string
    {
        $value = $this->data['proofExpiresAt'] ?? ($this->data['expiresAt'] ?? null);
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * The browser-facing bootstrap payload for the Lunixi portal SDK.
     *
     * @return array{portalToken:string, sessionProof:string, proofNonce:string, proofExpiresAt:?string}
     */
    public function toBootstrap(): array
    {
        return [
            'portalToken' => $this->portalToken(),
            'sessionProof' => $this->sessionProof(),
            'proofNonce' => $this->proofNonce(),
            'proofExpiresAt' => $this->proofExpiresAt(),
        ];
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
