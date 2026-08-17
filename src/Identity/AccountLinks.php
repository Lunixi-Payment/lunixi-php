<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Identity;

/**
 * The linked-account graph for a session (response of
 * `GET /api/v1/identity/live-sessions/{traceId}/account-links`). Surfaces every
 * OTHER account that shares this device/fingerprint/IP — the core "one person,
 * many accounts" signal. Account ids are returned HASHED (no PII).
 */
final class AccountLinks
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $this->data = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : $response;
    }

    public function totalLinkedSessions(): int
    {
        return (int) ($this->data['totalLinkedSessions'] ?? 0);
    }

    /** @return string[] Distinct account key hashes linked to this device/identity. */
    public function linkedAccounts(): array
    {
        $rows = $this->data['linkedAccounts'] ?? [];
        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    /** @return string[] Distinct trial-actor hashes (anonymous/guest linkage). */
    public function linkedTrials(): array
    {
        $rows = $this->data['linkedTrials'] ?? [];
        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    /** Distinct OTHER accounts already seen on this device (excludes the current one). */
    public function linkedAccountCount(): int
    {
        return count($this->linkedAccounts());
    }

    /**
     * Total distinct accounts tied to the device, counting the current session.
     *
     * The gateway returns `linkedAccounts` with the current trace excluded
     * (`trace_id <> :traceId`), so the device's true account count is the linked
     * others plus the one being looked up. This is the number the admin surface
     * shows as "accounts on device".
     */
    public function accountCount(): int
    {
        return $this->linkedAccountCount() + 1;
    }

    /**
     * True when the device is shared across at least `$threshold` distinct
     * accounts (counting the current one). At the default threshold of 2, a
     * device that already carries one other account trips it — i.e. the classic
     * "this is the second account on this device" abuse signal, not just the
     * third-and-beyond.
     */
    public function isShared(int $threshold = 2): bool
    {
        return $this->accountCount() >= max(2, $threshold);
    }

    /**
     * The linked sessions, each with its own linkReasons (why it's linked).
     *
     * @return array<int,array<string,mixed>>
     */
    public function sessions(): array
    {
        $rows = $this->data['sessions'] ?? [];
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * The de-duplicated set of reasons across all linked sessions
     * (same_device_cluster, same_fingerprint, same_ip_asn, …).
     *
     * @return string[]
     */
    public function linkReasons(): array
    {
        $reasons = [];
        foreach ($this->sessions() as $session) {
            if (is_array($session) && isset($session['linkReasons']) && is_array($session['linkReasons'])) {
                foreach ($session['linkReasons'] as $reason) {
                    $reasons[(string) $reason] = true;
                }
            }
        }
        return array_keys($reasons);
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
