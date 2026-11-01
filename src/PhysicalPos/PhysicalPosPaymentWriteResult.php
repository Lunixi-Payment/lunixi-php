<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * Answer of createPayment(), createReversal() and cancelPayment().
 *
 * A new payment or reversal is HTTP 202 Accepted: the start command was
 * queued, the payment is `NOT_STARTED`. It means "accepted", not "paid" —
 * follow the outcome by webhook or getPayment(). The same Idempotency-Key with
 * the same body answers 200 with `replayed: true` and the payment's CURRENT
 * view. Cancel always answers 200; read paymentStatus(): only `EXPIRED` with
 * `MERCHANT_CANCELLED_BEFORE_DISPATCH` means it was cancelled.
 *
 * A result exists only when the answer carried a readable payment; any other
 * 2xx is reported as PhysicalPosOutcomeUnknownException.
 */
final class PhysicalPosPaymentWriteResult
{
    private PhysicalPosPayment $payment;

    private bool $replayed;

    private int $statusCode;

    private string $idempotencyKey;

    public function __construct(PhysicalPosPayment $payment, bool $replayed, int $statusCode, string $idempotencyKey)
    {
        $this->payment = $payment;
        $this->replayed = $replayed;
        $this->statusCode = $statusCode;
        $this->idempotencyKey = $idempotencyKey;
    }

    public function payment(): PhysicalPosPayment
    {
        return $this->payment;
    }

    /** True when the same key was used before; the payment is its current view. */
    public function replayed(): bool
    {
        return $this->replayed;
    }

    /** HTTP status: 202 new payment / reversal, 200 replay or cancel. */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** True for HTTP 202 (a new payment or reversal was queued). */
    public function accepted(): bool
    {
        return $this->statusCode === 202;
    }

    /** The key that was sent (yours; generated only for a cancel without one). Keep it for retries. */
    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
