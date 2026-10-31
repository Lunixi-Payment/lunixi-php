<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

use Lunixi\Sdk\Payment\Currency;

/** Exact platform decimal strings to gateway minor units. Never pass a float. */
final class Money
{
    public const MAX_MINOR = 9007199254740991;

    /** ISO-4217 minor-unit exponent for the SDK's supported currency set. */
    public static function exponent(string $currency): int
    {
        if ($currency !== strtoupper($currency) || !Currency::isSupported($currency)) { throw new \InvalidArgumentException('A supported uppercase currency is required.'); }
        return $currency === 'JPY' ? 0 : ($currency === 'KWD' ? 3 : 2);
    }

    /** @param mixed $decimal */
    public static function minor($decimal, int $exponent = 2): int
    {
        if (!is_string($decimal) || $exponent < 0 || $exponent > 3 || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $decimal, $m)) {
            throw new \InvalidArgumentException('A nonnegative canonical decimal string is required.');
        }
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $exponent && trim(substr($fraction, $exponent), '0') !== '') {
            throw new \InvalidArgumentException('Amount has fractional minor units; round using the platform tax rules first.');
        }
        return self::integer($m[1] . str_pad(substr($fraction, 0, $exponent), $exponent, '0'));
    }

    /** @param mixed $value */
    public static function integer($value): int
    {
        if (PHP_INT_SIZE < 8 || (!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) {
            throw new \InvalidArgumentException('An exact nonnegative minor-unit integer is required on a 64-bit runtime.');
        }
        $digits = ltrim((string) $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $max = (string) self::MAX_MINOR;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new \InvalidArgumentException('Amount exceeds the gateway safe integer limit.');
        }
        return (int) $digits;
    }

    public static function decimal(int $minor, int $exponent = 2): string
    {
        self::integer($minor);
        if ($exponent < 0 || $exponent > 3) {
            throw new \InvalidArgumentException('Unsupported currency exponent.');
        }
        if ($exponent === 0) { return (string) $minor; }
        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    /**
     * Converts a persisted base-currency decimal using a persisted decimal rate.
     * Uses exact BCMath and half-up currency rounding, never platform floats.
     * Adapters using this optional method must require ext-bcmath explicitly.
     * @param mixed $decimal
     * @param mixed $rate
     */
    public static function convertedMinor($decimal, $rate, int $exponent = 2): int
    {
        if (!function_exists('bcmul')) { throw new \RuntimeException('Exact currency conversion requires ext-bcmath.'); }
        foreach ([$decimal, $rate] as $value) {
            if (!is_string($value) || strlen($value) > 64 || !preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)) { throw new \InvalidArgumentException('Persisted decimal amount and rate strings are required.'); }
        }
        if ($exponent < 0 || $exponent > 3 || bccomp($rate, '0', 64) <= 0) { throw new \InvalidArgumentException('A positive rate and currency exponent are required.'); }
        $fraction = static function (string $value): int { $dot = strpos($value, '.'); return $dot === false ? 0 : strlen($value) - $dot - 1; };
        $scale = max($fraction($decimal) + $fraction($rate), $exponent + 1);
        $product = bcmul($decimal, $rate, $scale);
        $halfUnit = ['0.5', '0.05', '0.005', '0.0005'][$exponent];
        $rounded = bcdiv(bcadd($product, $halfUnit, $scale), '1', $exponent);
        return self::minor($rounded, $exponent);
    }
}
