<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * Result of recording a single transaction event.
 *
 * `rowId()` is present only when a row was actually written; on deduplication the
 * gateway returns no row id, which is why {@see deduplicated()} is the field to
 * branch on rather than the absence of an id.
 */
final class FraudEventResult
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function eventId(): string
    {
        return isset($this->data['eventId']) && is_string($this->data['eventId'])
            ? $this->data['eventId']
            : '';
    }

    /** Row id of the stored event, or null when the event was deduplicated. */
    public function rowId(): ?string
    {
        return isset($this->data['id']) && is_string($this->data['id']) ? $this->data['id'] : null;
    }

    /** True when an event with this `eventId` already existed for the account. */
    public function deduplicated(): bool
    {
        return (bool) ($this->data['deduplicated'] ?? false);
    }

    /** How many published flows matched this event and ran. */
    public function matchedFlowCount(): ?int
    {
        return isset($this->data['matchedFlowCount']) && is_int($this->data['matchedFlowCount'])
            ? $this->data['matchedFlowCount']
            : null;
    }

    /**
     * Validation message for THIS event inside a batch response.
     *
     * A batch answers `200` even when some of its events were rejected, so this is
     * the only place a per-event failure is visible.
     */
    public function error(): ?string
    {
        return isset($this->data['error']) && is_string($this->data['error']) ? $this->data['error'] : null;
    }

    public function failed(): bool
    {
        return $this->error() !== null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
