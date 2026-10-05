<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * A plan recurrence rule (the gateway's structured billing rule — RFC-5545
 * derived). Minimal required form is a frequency + interval; the rest refine
 * the schedule.
 *
 *   $rule = (new BillingRule('MONTHLY', 1))->withTimezone('Europe/Istanbul');
 */
final class BillingRule
{
    public const DAILY = 'DAILY';
    public const WEEKLY = 'WEEKLY';
    public const MONTHLY = 'MONTHLY';
    public const YEARLY = 'YEARLY';

    private const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::YEARLY];

    private string $frequency;
    private int $interval;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(string $frequency, int $interval = 1)
    {
        $frequency = strtoupper($frequency);
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new ConfigurationException("BillingRule frequency must be one of DAILY|WEEKLY|MONTHLY|YEARLY, got '{$frequency}'.");
        }
        if ($interval < 1) {
            throw new ConfigurationException("BillingRule interval must be >= 1.");
        }
        $this->frequency = $frequency;
        $this->interval = $interval;
    }

    /** @param int[] $days Days of month (1..31). */
    public function withByMonthDay(array $days): self
    {
        $this->optional['byMonthDay'] = array_values(array_map('intval', $days));
        return $this;
    }

    /** @param string[] $days Week days (MO,TU,WE,TH,FR,SA,SU). */
    public function withByWeekDay(array $days): self
    {
        $this->optional['byWeekDay'] = array_values(array_map('strval', $days));
        return $this;
    }

    public function withTimezone(string $ianaTimezone): self
    {
        $this->optional['timezone'] = $ianaTimezone;
        return $this;
    }

    public function withLastDayOfMonthFix(bool $enabled): self
    {
        $this->optional['lastDayOfMonthFix'] = $enabled;
        return $this;
    }

    /** Maximum number of occurrences (0 = unlimited). */
    public function withCount(int $count): self
    {
        $this->optional['count'] = max(0, $count);
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge([
            'frequency' => $this->frequency,
            'interval' => $this->interval,
        ], $this->optional);
    }
}
