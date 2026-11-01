<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * Common read helpers of the physical POS response objects.
 *
 * The gateway sends `null` for a field without a value (it never sends an
 * empty string), so optional accessors return null. Amount accessors return
 * null — never 0 — when the field is missing, so a broken answer cannot look
 * like "0 kuruş". HTTP amounts are canonical integer strings
 * in minor units (kuruş for TRY); times are ISO-8601 UTC strings with
 * milliseconds.
 */
abstract class PhysicalPosResource
{
    /** @var array<string,mixed> */
    protected array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        PhysicalPosMoney::validate($data);
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
        $value = $this->data[$key] ?? null;
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : 0;
    }

    protected function intOrNull(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : null;
    }

    protected function bool(string $key): bool
    {
        return ($this->data[$key] ?? null) === true;
    }

    /** @return array<string,mixed> */
    protected function object(string $key): array
    {
        $value = $this->data[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed>|null */
    protected function objectOrNull(string $key): ?array
    {
        $value = $this->data[$key] ?? null;
        return is_array($value) ? $value : null;
    }

    /** @return array<int,array<string,mixed>> */
    protected function list(string $key): array
    {
        $value = $this->data[$key] ?? null;
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @return PhysicalPosPayment[] */
    protected function payments(string $key): array
    {
        return array_map(static fn (array $row): PhysicalPosPayment => new PhysicalPosPayment($row), $this->list($key));
    }
}
