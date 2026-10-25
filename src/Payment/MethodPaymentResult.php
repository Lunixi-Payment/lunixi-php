<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Response of directMethodPayment(), confirmPaymentAction() and
 * resendPaymentAction(): `paymentId`, `orderId`, `status`, `paymentMethod`,
 * `providerCode` and, while the buyer must act (HTTP 202), `action`.
 *
 * A declined payment is HTTP 400 and throws ApiException; the same fields are
 * in its body: `new MethodPaymentResult($exception->getResponseBody() ?? [])`.
 */
final class MethodPaymentResult
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

    public function paymentId(): string
    {
        return (string) ($this->data['paymentId'] ?? '');
    }

    public function orderId(): string
    {
        return (string) ($this->data['orderId'] ?? '');
    }

    /** One of the PaymentStatus::* values. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    /** One of the PaymentMethod::* codes. */
    public function paymentMethod(): string
    {
        return (string) ($this->data['paymentMethod'] ?? '');
    }

    public function providerCode(): ?string
    {
        $code = $this->data['providerCode'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** Present while the buyer must act. */
    public function action(): ?PaymentAction
    {
        $action = $this->data['action'] ?? null;

        return is_array($action) ? new PaymentAction($action) : null;
    }

    /**
     * A card saved by this payment (card, 3D Secure with `cardSave`):
     * storedCardToken, cardUserKey, maskedNumber, … No PAN.
     *
     * @return array<string,mixed>|null
     */
    public function savedCard(): ?array
    {
        $card = $this->data['savedCard'] ?? null;

        return is_array($card) ? $card : null;
    }

    /** Gateway result code (e.g. `SUCCESS`, `payment.declined`). */
    public function code(): string
    {
        return (string) ($this->response['code'] ?? '');
    }

    /** Details of a decline or of a pending verification. @return array<string,mixed> */
    public function details(): array
    {
        return isset($this->response['details']) && is_array($this->response['details']) ? $this->response['details'] : [];
    }

    public function isCaptured(): bool
    {
        return in_array($this->status(), PaymentStatus::CAPTURED_STATES, true);
    }

    public function isAuthorized(): bool
    {
        return $this->status() === PaymentStatus::AUTHORIZED;
    }

    public function isFailed(): bool
    {
        return $this->status() === PaymentStatus::FAILED;
    }

    /** The provider's answer is being verified; the final result arrives by webhook. */
    public function needsReconciliation(): bool
    {
        return $this->status() === PaymentStatus::NEEDS_RECONCILIATION;
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
