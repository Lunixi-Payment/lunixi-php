<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Common read helpers of the bank-account response objects.
 *
 * The gateway leaves a field out when it has no value (an empty text, an
 * amount the bank did not report), so every optional accessor returns null
 * instead of an empty string or 0. Amounts are integers in minor units; times
 * are ISO-8601 strings with a time zone.
 */
abstract class BankConnectResource
{
    /** @var array<string,mixed> */
    protected array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return mixed Any raw field by name, or null when absent. */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    protected function str(string $key): string
    {
        $value = $this->data[$key] ?? null;
        return is_scalar($value) ? (string) $value : '';
    }

    protected function strOrNull(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function int(string $key): int
    {
        return $this->intOrNull($key) ?? 0;
    }

    protected function intOrNull(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : null;
    }

    protected function bool(string $key): bool
    {
        return ($this->data[$key] ?? false) === true;
    }

    /** @return array<string,mixed> */
    protected function object(string $key): array
    {
        $value = $this->data[$key] ?? null;
        return is_array($value) ? $value : [];
    }
}
