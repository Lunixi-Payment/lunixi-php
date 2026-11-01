<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

use Lunixi\Sdk\Commerce\Money;
use Lunixi\Sdk\Exception\ConfigurationException;

/** @internal Strict public money fields; opaque metadata and numeric counters are not traversed. */
final class PhysicalPosMoney
{
    private const FIELDS = ['amountFromMinor', 'amountMinor', 'amountToMinor', 'approvedAmountMinor', 'baseMinor', 'capturedMinor', 'lineTotalMinor', 'netBaseMinor', 'netMinor', 'netTaxMinor', 'refundBaseMinor', 'refundMinor', 'refundTaxMinor', 'refundedAmountMinor', 'refundedMinor', 'reservedAmountMinor', 'reservedMinor', 'reversalReservedMinor', 'reversedMinor', 'saleBaseMinor', 'saleMinor', 'saleTaxMinor', 'unitAmountMinor', 'unitPriceMinor', 'voidBaseMinor', 'voidMinor', 'voidTaxMinor'];
    private const CONTAINERS = ['data', 'payment', 'payments', 'items', 'refundItems', 'taxes', 'rows', 'transaction', 'transactions', 'root', 'children', 'itemSummary', 'amountTotals', 'reported', 'system', 'difference', 'totals', 'lines'];
    private const NULLABLE = ['approvedAmountMinor', 'unitAmountMinor'];

    /** @param mixed $value */
    public static function validate($value, bool $difference = false): void
    {
        if (!is_array($value)) { return; }
        foreach ($value as $key => $entry) {
            if (is_int($key)) { self::validate($entry, $difference); }
            elseif (in_array($key, self::FIELDS, true)) {
                if ($entry === null && in_array($key, self::NULLABLE, true)) { continue; }
                $signed = $difference || strpos($key, 'net') === 0;
                try {
                    Money::canonical($entry, $signed);
                    if (Money::compare($entry, Money::MAX_MINOR) > 0 || ($signed && Money::compare($entry, '-9223372036854775808') < 0)) {
                        throw new \InvalidArgumentException('Money exceeds signed64.');
                    }
                } catch (\InvalidArgumentException $error) {
                    throw new ConfigurationException("'" . $key . "' must be a canonical minor-unit string in the signed64 range.");
                }
            } elseif (in_array($key, self::CONTAINERS, true)) { self::validate($entry, $difference || $key === 'difference'); }
        }
    }
}
