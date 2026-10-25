<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * One reconciliation CSV file (exportReconciliation()): UTF-8 with BOM, one
 * line per policy (an order without a policy has one line with empty policy
 * columns), amounts in minor units.
 *
 * A file holds at most {@see InsuranceClient::EXPORT_ORDER_CAP} orders. When
 * it was cut, {@see truncated()} is true and {@see nextCursor()} continues it
 * (pass it as `cursor`). When the gateway could not finish the file it ends
 * with an abort line: {@see aborted()} is true and the file is incomplete —
 * request it again.
 */
final class InsuranceReconciliationExport
{
    private const TRUNCATED_LINE = '/^# truncated=true orderCap=(\d+) nextCursor=(\S+)\s*$/';
    private const ABORTED_LINE = '/^# export-aborted=true\s*$/';

    private string $contentType;

    private string $bytes;

    private bool $truncated;

    private ?string $nextCursor;

    private bool $aborted;

    public function __construct(string $contentType, string $bytes)
    {
        $this->contentType = $contentType;
        $this->bytes = $bytes;

        $lines = array_values(array_filter(
            preg_split('/\r?\n/', $bytes) ?: [],
            static fn (string $line): bool => $line !== ''
        ));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        $this->truncated = preg_match(self::TRUNCATED_LINE, $last, $match) === 1;
        $this->nextCursor = $this->truncated ? $match[2] : null;
        $this->aborted = preg_match(self::ABORTED_LINE, $last) === 1;
    }

    /** `text/csv; charset=utf-8`. */
    public function contentType(): string
    {
        return $this->contentType;
    }

    /** The raw CSV bytes, including the trailing continuation or abort line when present. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /** The file was cut at the order cap; continue with {@see nextCursor()}. */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** Cursor of the next file, or null when the export is complete. */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /** The file ends with the abort line: it is incomplete, request it again. */
    public function aborted(): bool
    {
        return $this->aborted;
    }
}
