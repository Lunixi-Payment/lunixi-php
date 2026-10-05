<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * Result of a batch event-ingest call.
 *
 * 🔴 A BATCH CAN SUCCEED PARTIALLY. The endpoint answers `200` and keeps writing
 *    the remaining events when one of them fails validation, so the HTTP status
 *    alone never proves the batch landed. {@see failed()} and {@see failures()}
 *    are the fields to check; an integration that reads only the status code
 *    silently loses events.
 */
final class FraudEventBatchResult
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var list<FraudEventResult> */
    private array $results;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
        $rows = isset($data['results']) && is_array($data['results']) ? $data['results'] : [];
        $this->results = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->results[] = new FraudEventResult($row);
            }
        }
    }

    public function total(): int
    {
        return (int) ($this->data['total'] ?? 0);
    }

    public function inserted(): int
    {
        return (int) ($this->data['inserted'] ?? 0);
    }

    public function deduplicated(): int
    {
        return (int) ($this->data['deduplicated'] ?? 0);
    }

    public function failed(): int
    {
        return (int) ($this->data['failed'] ?? 0);
    }

    /** @return list<FraudEventResult> */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * Only the events that were rejected — resend these with the same `eventId`
     * once the body is corrected.
     *
     * @return list<FraudEventResult>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (FraudEventResult $result): bool => $result->failed()
        ));
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
