<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * Answer of a management write that takes an Idempotency-Key (create store,
 * register, terminal, bank row, device; close a device link; move a device to
 * storage; retire a device).
 *
 * {@see record()} is the main record (`store`, `register`, `terminal`,
 * `acquirer` or `device`); {@see data()} is the whole answer (e.g. `link`,
 * `closedAssignment`). Creates answer 201, a replay of the same key and body
 * 200 with `replayed: true`.
 */
final class PhysicalPosWriteResult
{
    /** @var array<string,mixed> */
    private array $data;

    private string $recordKey;

    private int $statusCode;

    private string $idempotencyKey;

    /** @param array<string,mixed> $data */
    public function __construct(array $data, string $recordKey, int $statusCode, string $idempotencyKey)
    {
        $this->data = $data;
        $this->recordKey = $recordKey;
        $this->statusCode = $statusCode;
        $this->idempotencyKey = $idempotencyKey;
    }

    /** @return array<string,mixed> */
    public function record(): array
    {
        $value = $this->data[$this->recordKey] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> The whole answer. */
    public function data(): array
    {
        return $this->data;
    }

    public function replayed(): bool
    {
        return ($this->data['replayed'] ?? false) === true;
    }

    /** HTTP status: 201 created, 200 replay or a state change. */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** The key that was sent (generated when you passed none). */
    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
