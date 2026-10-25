<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Answer of PaymentClient::createSplitPayment(): `splitPaymentId`, `status`,
 * `steps` (the order the platform set), `pendingStep` (what the payment waits
 * for) and `expiresAt` (end of the payment window).
 *
 * A failed split payment throws ApiException; the same fields are in its body:
 * `new SplitPaymentResult($e->getResponseBody() ?? [])`.
 */
final class SplitPaymentResult
{
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

    public function splitPaymentId(): string
    {
        return (string) ($this->data['splitPaymentId'] ?? '');
    }

    /**
     * Checkout Form split answers only (`form/split/init`, `form/split/status`
     * and the continuation page): the main (checkout) payment's `paymentId`,
     * the id sent to your `callbackUrl`. Null on `POST /payments/split`.
     */
    public function paymentId(): ?string
    {
        $value = $this->data['paymentId'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** One of the SplitPaymentStatus::* values. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function isCompleted(): bool
    {
        return $this->status() === SplitPaymentStatus::COMPLETED;
    }

    /** The payment is still running (waiting for a step or being processed). */
    public function isInProgress(): bool
    {
        return in_array($this->status(), SplitPaymentStatus::IN_PROGRESS, true);
    }

    /** The same Idempotency-Key was used before; no new payment was created. */
    public function isDuplicate(): bool
    {
        return ($this->data['duplicate'] ?? false) === true;
    }

    public function needsReconciliation(): bool
    {
        return ($this->data['needsReconciliation'] ?? false) === true;
    }

    /** Another request is processing this split payment; read it again shortly. */
    public function lockContended(): bool
    {
        return ($this->data['lockContended'] ?? false) === true;
    }

    /** @return SplitPaymentStep[] */
    public function steps(): array
    {
        $steps = is_array($this->data['steps'] ?? null) ? $this->data['steps'] : [];

        return array_values(array_map(
            static fn ($step): SplitPaymentStep => new SplitPaymentStep(is_array($step) ? $step : []),
            $steps
        ));
    }

    public function pendingStep(): ?SplitPaymentPendingStep
    {
        $pending = $this->data['pendingStep'] ?? null;

        return is_array($pending) ? new SplitPaymentPendingStep($pending) : null;
    }

    /** End of the payment window (ISO 8601); null when there is none or the non-reversible leg has started. */
    public function expiresAt(): ?string
    {
        $value = $this->data['expiresAt'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Checkout Form split answers only (form and continuation page): the
     * 3D Secure display the payment started with (ThreeDsDisplay::*). Null on
     * `POST /payments/split`.
     */
    public function threeDsDisplay(): ?string
    {
        $value = $this->data['threeDsDisplay'] ?? null;

        return $value === ThreeDsDisplay::INLINE || $value === ThreeDsDisplay::REDIRECT ? $value : null;
    }

    /**
     * Continuation page answers only: the result once the split payment has
     * concluded — `outcome` (SplitCompletionOutcome::*), `code` (split status),
     * `message`, `paymentId`, `orderId` and `merchantCallback` (`url`,
     * `method`, `fields`; null when the intent has no `callbackUrl`). Null while
     * steps remain and on `POST /payments/split`.
     *
     * @return array<string,mixed>|null
     */
    public function completion(): ?array
    {
        $value = $this->data['completion'] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * Legs as the saga reports them: id, sequence, legStatus, paymentIntentId,
     * paymentMethod, splitBehavior, providerCode, legAmount, installmentCount.
     *
     * @return list<array<string,mixed>>
     */
    public function legs(): array
    {
        $legs = is_array($this->data['legs'] ?? null) ? $this->data['legs'] : [];

        return array_values(array_filter($legs, 'is_array'));
    }

    /** Gateway result code: the split status, or `split_pay.*` on a refusal. */
    public function code(): string
    {
        return (string) ($this->response['code'] ?? '');
    }

    /** @return array<string,mixed> The response `data`. */
    public function raw(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> The full response envelope. */
    public function response(): array
    {
        return $this->response;
    }
}
