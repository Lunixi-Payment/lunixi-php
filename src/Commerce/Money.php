<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

use Lunixi\Sdk\Payment\Currency;

/** Exact platform decimal strings to gateway minor units. Never pass a float. */
final class Money
{
    public const MAX_MINOR = '9223372036854775807';

    /** ISO-4217 minor-unit exponent for the SDK's supported currency set. */
    public static function exponent(string $currency): int
    {
        if ($currency !== strtoupper($currency) || !Currency::isSupported($currency)) { throw new \InvalidArgumentException('A supported uppercase currency is required.'); }
        return $currency === 'JPY' ? 0 : ($currency === 'KWD' ? 3 : 2);
    }

    /** @param mixed $decimal */
    public static function minor($decimal, int $exponent): string
    {
        if (!is_string($decimal) || $exponent < 0 || $exponent > 3 || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $decimal, $m)) {
            throw new \InvalidArgumentException('A nonnegative canonical decimal string is required.');
        }
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $exponent && trim(substr($fraction, $exponent), '0') !== '') {
            throw new \InvalidArgumentException('Amount has fractional minor units; round using the platform tax rules first.');
        }
        return self::integer(ltrim($m[1] . str_pad(substr($fraction, 0, $exponent), $exponent, '0'), '0') ?: '0');
    }

    /**
     * Validate a fiat minor-unit string without coercion. @param mixed $value
     */
    public static function integer($value): string
    {
        self::canonical($value);
        if (self::compare($value, self::MAX_MINOR) > 0) {
            throw new \InvalidArgumentException('Amount exceeds the signed64 fiat storage limit.');
        }
        return $value;
    }

    /**
     * Validate the unlimited wire domain (also used by crypto). @param mixed $value
     */
    public static function canonical($value, bool $signed = false): string
    {
        $pattern = $signed ? '/^(0|-?[1-9][0-9]*)$/D' : '/^(0|[1-9][0-9]*)$/D';
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException('A canonical minor-unit integer string is required.');
        }
        return $value;
    }

    /**
     * Nonnegative request money in the fiat domain. @param mixed $value
     */
    public static function nonnegative($value, string $field): string
    {
        try { return self::integer($value); }
        catch (\InvalidArgumentException $error) { throw new \Lunixi\Sdk\Exception\ConfigurationException("'" . $field . "': " . $error->getMessage()); }
    }

    /**
     * Exact native BIGINT storage decoding only; never use for public payloads. @param mixed $value
     */
    public static function fromStorage($value): string
    {
        if (is_int($value)) { $value = (string) $value; }
        return self::integer($value);
    }

    /**
     * Request validation with the SDK's configuration exception. @param mixed $value
     */
    public static function positive($value, string $field = 'amount'): string
    {
        try { $value = self::integer($value); }
        catch (\InvalidArgumentException $error) { throw new \Lunixi\Sdk\Exception\ConfigurationException($field . ': ' . $error->getMessage()); }
        if ($value === '0') { throw new \Lunixi\Sdk\Exception\ConfigurationException($field . ' must be a positive integer string in minor units.'); }
        return $value;
    }

    /**
     * Compare signed canonical integers without PHP numeric-string coercion.
     * @param mixed $left Canonical money string; other types are rejected before coercion.
     * @param mixed $right Canonical money string; other types are rejected before coercion.
     */
    public static function compare($left, $right): int
    {
        self::canonical($left, true); self::canonical($right, true);
        $ln = $left[0] === '-'; $rn = $right[0] === '-';
        if ($ln !== $rn) { return $ln ? -1 : 1; }
        $a = $ln ? substr($left, 1) : $left; $b = $rn ? substr($right, 1) : $right;
        $result = strlen($a) <=> strlen($b);
        if ($result === 0) { $result = strcmp($a, $b) <=> 0; }
        return $ln ? -$result : $result;
    }

    /**
     * @param mixed $left Canonical money string; other types are rejected before coercion.
     * @param mixed $right Canonical money string; other types are rejected before coercion.
     */
    public static function minimum($left, $right): string
    {
        return self::compare($left, $right) <= 0 ? $left : $right;
    }

    /**
     * @param mixed $left Canonical money string; other types are rejected before coercion.
     * @param mixed $right Canonical money string; other types are rejected before coercion.
     */
    public static function difference($left, $right): string
    {
        return self::compare($left, $right) >= 0 ? self::subtract($left, $right) : '-' . self::subtract($right, $left);
    }

    /**
     * Exact nonnegative addition; callers validate their domain's maximum.
     * @param mixed $left Canonical money string; other types are rejected before coercion.
     * @param mixed $right Canonical money string; other types are rejected before coercion.
     */
    public static function add($left, $right): string
    {
        self::canonical($left); self::canonical($right);
        $i = strlen($left) - 1; $j = strlen($right) - 1; $carry = 0; $result = '';
        while ($i >= 0 || $j >= 0 || $carry !== 0) {
            $digit = ($i >= 0 ? ord($left[$i--]) - 48 : 0) + ($j >= 0 ? ord($right[$j--]) - 48 : 0) + $carry;
            $result = (string) ($digit % 10) . $result; $carry = intdiv($digit, 10);
        }
        return $result;
    }

    /**
     * Exact nonnegative subtraction; a negative result fails closed.
     * @param mixed $left Canonical money string; other types are rejected before coercion.
     * @param mixed $right Canonical money string; other types are rejected before coercion.
     */
    public static function subtract($left, $right): string
    {
        self::canonical($left); self::canonical($right);
        if (self::compare($left, $right) < 0) { throw new \InvalidArgumentException('Negative money difference.'); }
        $j = strlen($right) - 1; $borrow = 0; $result = '';
        for ($i = strlen($left) - 1; $i >= 0; --$i) {
            $digit = ord($left[$i]) - 48 - $borrow - ($j >= 0 ? ord($right[$j--]) - 48 : 0);
            $borrow = $digit < 0 ? 1 : 0; if ($borrow) { $digit += 10; }
            $result = (string) $digit . $result;
        }
        return ltrim($result, '0') ?: '0';
    }

    /**
     * Exact floor(value * numerator / denominator), e.g. seller allocation.
     * @param mixed $value Canonical money string; other types are rejected before coercion.
     * @param mixed $numerator Canonical money string; other types are rejected before coercion.
     * @param mixed $denominator Canonical money string; other types are rejected before coercion.
     */
    public static function prorate($value, $numerator, $denominator): string
    {
        self::canonical($value); self::canonical($numerator); self::canonical($denominator);
        if ($denominator === '0') { throw new \InvalidArgumentException('A positive denominator is required.'); }
        $product = '0';
        foreach (str_split($numerator) as $digit) {
            $product = $product === '0' ? '0' : $product . '0';
            for ($i = 0; $i < ord($digit) - 48; ++$i) { $product = self::add($product, $value); }
        }
        $remainder = '0'; $quotient = '';
        foreach (str_split($product) as $digit) {
            $remainder = ltrim(($remainder === '0' ? '' : $remainder) . $digit, '0') ?: '0'; $q = 0;
            while (self::compare($remainder, $denominator) >= 0) { $remainder = self::subtract($remainder, $denominator); ++$q; }
            $quotient .= (string) $q;
        }
        return ltrim($quotient, '0') ?: '0';
    }

    /**
     * @param mixed $minor Canonical money string; other types are rejected before coercion.
     */
    public static function decimal($minor, int $exponent): string
    {
        self::integer($minor);
        if ($exponent < 0 || $exponent > 3) { throw new \InvalidArgumentException('Unsupported currency exponent.'); }
        if ($exponent === 0) { return $minor; }
        $digits = str_pad($minor, $exponent + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    /**
     * Converts a persisted base-currency decimal using a persisted decimal rate.
     * Uses exact BCMath and half-up currency rounding, never platform floats.
     * Adapters using this optional method must require ext-bcmath explicitly.
     * @param mixed $decimal
     * @param mixed $rate
     */
    public static function convertedMinor($decimal, $rate, int $exponent): string
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
