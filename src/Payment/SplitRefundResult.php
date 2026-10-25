<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Answer of PaymentClient::refundSplitPayment(): the refund of the main
 * payment spread over the legs.
 *
 * When some legs could not be refunded the gateway answers
 * `split_pay.refund.incomplete` (ApiException); the same fields are in its
 * body: `new SplitRefundResult($e->getResponseBody() ?? [])`. Retry with the
 * same Idempotency-Key: legs already refunded are not refunded again.
 */
final class SplitRefundResult
{
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_FAILED = 'FAILED';

    /** Result of one leg (`legs()[].status`). */
    public const LEG_PENDING = 'PENDING';
    public const LEG_SUCCEEDED = 'SUCCEEDED';
    public const LEG_FAILED = 'FAILED';

    /** @var array<string,mixed> */
    private array $response;

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $this->response = $response;
        $this->data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    public function refundRequestId(): string
    {
        return (string) ($this->data['refundRequestId'] ?? '');
    }

    public function splitPaymentId(): string
    {
        return (string) ($this->data['splitPaymentId'] ?? '');
    }

    /** Checkout Form main payment; null for an API split payment. */
    public function parentPaymentId(): ?string
    {
        $value = $this->data['parentPaymentId'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** One of the STATUS_* constants. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function isCompleted(): bool
    {
        return $this->status() === self::STATUS_COMPLETED;
    }

    public function requestedAmount(): int
    {
        return (int) ($this->data['requestedAmount'] ?? 0);
    }

    /** Refunded in this request (minor units). */
    public function refundedAmount(): int
    {
        return (int) ($this->data['refundedAmount'] ?? 0);
    }

    /** What is left to refund after this request. */
    public function refundableAmount(): int
    {
        return (int) ($this->data['refundableAmount'] ?? 0);
    }

    /** Amount of the non-reversible legs (never refunded). */
    public function nonRefundableAmount(): int
    {
        return (int) ($this->data['nonRefundableAmount'] ?? 0);
    }

    /** A completed request repeated with the same key; nothing was sent to the bank. */
    public function isDuplicate(): bool
    {
        return ($this->data['duplicate'] ?? false) === true;
    }

    /**
     * Per leg: legId, sequence, paymentIntentId, paymentMethod, providerCode,
     * amount (minor units), status (LEG_*), errorCode.
     *
     * @return list<array<string,mixed>>
     */
    public function legs(): array
    {
        $legs = is_array($this->data['legs'] ?? null) ? $this->data['legs'] : [];

        return array_values(array_filter($legs, 'is_array'));
    }

    /** `SPLIT_REFUND_COMPLETED` or `split_pay.refund.incomplete`. */
    public function code(): string
    {
        return (string) ($this->response['code'] ?? '');
    }

    /** @return array<string,mixed> The response `data`. */
    public function raw(): array
    {
        return $this->data;
    }
}
