<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Senetle Ödeme (promissory-note payment, `paymentMethod: PROMISSORY_NOTE`):
 * the plan rules the SDK checks before sending.
 *
 * The plan is either a schedule (`[['amount' => …, 'dueDate' => 'YYYY-MM-DD'], …]`)
 * or a note count (`noteCount`), never both. Checked here: integer minor-unit
 * amounts adding up to the payment amount, `YYYY-MM-DD` calendar dates in
 * ascending order (the same day twice is fine), at most 24 rows, a note count
 * of 1–24. That a due date lies after today (Istanbul calendar) and that the
 * count is within your provider connection limits is checked by the gateway
 * (400 `payment.method.block_invalid`).
 */
final class PromissoryNotePlan
{
    /** Most rows of a schedule (and most notes of a payment). */
    public const MAX_ITEMS = 24;

    private const DUE_DATE_PATTERN = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/';
    private const NOTE_COUNT_PATTERN = '/^[1-9][0-9]?$/';

    private function __construct()
    {
    }

    /**
     * A schedule: 1–24 rows `['amount' => int ≥ 1, 'dueDate' => 'YYYY-MM-DD']`
     * in ascending date order. Returns the rows as a list.
     *
     * @param array<mixed> $schedule
     * @return list<array{amount:int,dueDate:string}>
     */
    public static function requireSchedule(array $schedule, string $path = 'promissoryNote.schedule'): array
    {
        if ($schedule === [] || !self::isList($schedule)) {
            throw new ConfigurationException(sprintf('%s must be a non-empty list of [amount, dueDate] rows.', $path));
        }
        if (count($schedule) > self::MAX_ITEMS) {
            throw new ConfigurationException(sprintf('%s can have at most %d rows; got %d.', $path, self::MAX_ITEMS, count($schedule)));
        }

        $rows = [];
        $previousDueDate = null;
        foreach ($schedule as $index => $row) {
            $rowPath = sprintf('%s[%d]', $path, $index);
            if (!is_array($row) || ($row !== [] && self::isList($row))) {
                throw new ConfigurationException(sprintf('%s must be an array with amount and dueDate.', $rowPath));
            }
            $unknown = array_values(array_diff(array_map('strval', array_keys($row)), ['amount', 'dueDate']));
            if ($unknown !== []) {
                throw new ConfigurationException(sprintf('%s has unknown field(s): %s. A row is amount and dueDate.', $rowPath, implode(', ', $unknown)));
            }
            $amount = $row['amount'] ?? null;
            if (!is_int($amount) || $amount < 1) {
                throw new ConfigurationException(sprintf('%s.amount must be a positive integer in minor units (e.g. 100000 for 1,000.00 TRY).', $rowPath));
            }
            $dueDate = $row['dueDate'] ?? null;
            if (!is_string($dueDate) || !self::isCalendarDate($dueDate)) {
                throw new ConfigurationException(sprintf('%s.dueDate must be a calendar date in YYYY-MM-DD form.', $rowPath));
            }
            if ($previousDueDate !== null && strcmp($dueDate, $previousDueDate) < 0) {
                throw new ConfigurationException(sprintf(
                    '%s.dueDate must not be earlier than the previous row (%s); list the notes in due-date order.',
                    $rowPath,
                    $previousDueDate
                ));
            }
            $previousDueDate = $dueDate;
            $rows[] = ['amount' => $amount, 'dueDate' => $dueDate];
        }

        return $rows;
    }

    /**
     * The rows of a schedule must add up to the payment amount.
     *
     * @param list<array{amount:int,dueDate:string}> $schedule
     */
    public static function assertMatchesAmount(array $schedule, int $amount, string $path = 'promissoryNote.schedule'): void
    {
        $total = array_sum(array_column($schedule, 'amount'));
        if ($total !== $amount) {
            throw new ConfigurationException(sprintf('%s adds up to %d; it must equal amount (%d).', $path, $total, $amount));
        }
    }

    /**
     * Number of notes when no schedule is given: an integer 1–24 or its digit
     * string. Returned as a string, the value of the `noteCount` option in
     * `inputFields` (`"1"` … `"N"`, N from your provider connection).
     *
     * @param mixed $count
     */
    public static function requireNoteCount($count, string $path = 'promissoryNote.noteCount'): string
    {
        $text = is_int($count) ? (string) $count : $count;
        if (!is_string($text) || preg_match(self::NOTE_COUNT_PATTERN, $text) !== 1 || (int) $text > self::MAX_ITEMS) {
            throw new ConfigurationException(sprintf('%s must be a number of notes from 1 to %d.', $path, self::MAX_ITEMS));
        }

        return $text;
    }

    /**
     * The plan of a `promissoryNote` block: exactly one of `schedule` or
     * `noteCount`; a schedule must add up to `$amount`.
     *
     * @internal
     * @param array<string,mixed> $block
     */
    public static function assertBlock(array $block, int $amount, string $path = 'promissoryNote'): void
    {
        $hasSchedule = isset($block['schedule']);
        $hasNoteCount = isset($block['noteCount']);
        if ($hasSchedule && $hasNoteCount) {
            throw new ConfigurationException(sprintf('%s: send either schedule or noteCount, not both.', $path));
        }
        if (!$hasSchedule && !$hasNoteCount) {
            throw new ConfigurationException(sprintf('%s needs the plan: a schedule (amount, dueDate rows) or a noteCount.', $path));
        }
        if ($hasNoteCount) {
            self::requireNoteCount($block['noteCount'], $path . '.noteCount');
            return;
        }
        $schedule = is_array($block['schedule']) ? $block['schedule'] : [];
        self::assertMatchesAmount(self::requireSchedule($schedule, $path . '.schedule'), $amount, $path . '.schedule');
    }

    private static function isCalendarDate(string $value): bool
    {
        if (preg_match(self::DUE_DATE_PATTERN, $value, $match) !== 1) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
