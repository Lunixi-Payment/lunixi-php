<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/** One note of a Senetle Ödeme payment (`notes[]` of PromissoryNotes). */
final class PromissoryNote
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function serialNumber(): string
    {
        return (string) ($this->data['serialNumber'] ?? '');
    }

    /** 1-based position of the note in the plan. */
    public function sequence(): int
    {
        return (int) ($this->data['sequence'] ?? 0);
    }

    /** Minor units. */
    public function amount(): int
    {
        return (int) ($this->data['amount'] ?? 0);
    }

    /** `YYYY-MM-DD`. */
    public function dueDate(): string
    {
        return (string) ($this->data['dueDate'] ?? '');
    }

    /** One of the PromissoryNoteStatus constants. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function paidAt(): ?string
    {
        return self::text($this->data['paidAt'] ?? null);
    }

    public function protestedAt(): ?string
    {
        return self::text($this->data['protestedAt'] ?? null);
    }

    public function cancelledAt(): ?string
    {
        return self::text($this->data['cancelledAt'] ?? null);
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    /** @param mixed $value */
    private static function text($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
