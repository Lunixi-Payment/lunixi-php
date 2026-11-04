<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * `enabledInstallments`: the installment counts the hosted payment form may
 * offer for one payment (same name and shape as iyzico's checkout-form field).
 *
 * The list only narrows what the buyer sees: the form shows the counts that are
 * also allowed by your panel installment settings, the card (BIN) and the
 * platform maximum. Not sending it means no restriction. `[1]` closes
 * installments (single payment only); `[1, 2, 3]` allows at most 3.
 *
 * Rules checked before sending: a non-empty list of integers from 1 to 36, no
 * duplicates (a duplicate is an error, it is not removed), and 1 must be in
 * the list. The list is sent in ascending order.
 */
final class EnabledInstallments
{
    public const MIN = 1;
    public const MAX = 36;

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $counts
     * @return list<int> The counts in ascending order.
     */
    public static function require(array $counts, string $field = 'enabledInstallments'): array
    {
        if ($counts === []) {
            throw new ConfigurationException(sprintf(
                "'%s' must not be empty; leave it out for no restriction, or send [1] to allow a single payment only.",
                $field
            ));
        }

        $seen = [];
        foreach ($counts as $count) {
            if (!is_int($count) || $count < self::MIN || $count > self::MAX) {
                throw new ConfigurationException(sprintf(
                    "'%s' values must be integers from %d to %d; got %s.",
                    $field,
                    self::MIN,
                    self::MAX,
                    var_export($count, true)
                ));
            }
            if (isset($seen[$count])) {
                throw new ConfigurationException(sprintf("'%s' has %d more than once.", $field, $count));
            }
            $seen[$count] = true;
        }
        if (!isset($seen[self::MIN])) {
            throw new ConfigurationException(sprintf("'%s' must contain 1 (single payment).", $field));
        }

        $sorted = array_keys($seen);
        sort($sorted);

        return $sorted;
    }
}
