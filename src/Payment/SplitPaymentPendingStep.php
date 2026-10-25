<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * What a split payment waits for (`pendingStep`).
 */
final class SplitPaymentPendingStep
{
    /** Send the buyer to redirectPath() (card 3D Secure). */
    public const THREE_DS = 'THREE_DS';
    /** Send the same request again with the same Idempotency-Key: the next non-card leg starts with its block. */
    public const START_METHOD = 'START_METHOD';
    /** Complete the action with PaymentClient::confirmPaymentAction(paymentId(), actionId(), …), then send the same request again. */
    public const ACTION = 'ACTION';
    /** The provider's result is being confirmed; read the split payment again later. */
    public const RESULT_PENDING = 'RESULT_PENDING';
    /** The lender's approval is awaited. */
    public const CREDIT_APPROVAL = 'CREDIT_APPROVAL';
    /** The buyer enters a card after the credit approval (Checkout Form). */
    public const CARD_INPUT = 'CARD_INPUT';
    /** The buyer enters a new card for the shortfall (Checkout Form). */
    public const REPLACEMENT_CARD = 'REPLACEMENT_CARD';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function legId(): string
    {
        return (string) ($this->data['legId'] ?? '');
    }

    public function sequence(): int
    {
        return (int) ($this->data['sequence'] ?? 0);
    }

    /** One of the constants of this class. */
    public function step(): string
    {
        return (string) ($this->data['step'] ?? '');
    }

    public function paymentMethod(): string
    {
        return (string) ($this->data['paymentMethod'] ?? '');
    }

    public function providerCode(): ?string
    {
        return self::text($this->data['providerCode'] ?? null);
    }

    /** Minor units. */
    public function amount(): ?int
    {
        return isset($this->data['amount']) ? (int) $this->data['amount'] : null;
    }

    /** Non-card leg's payment record (the `paymentId` of the action endpoints); null for a card. */
    public function paymentId(): ?string
    {
        return self::text($this->data['paymentId'] ?? null);
    }

    /** The open action (step ACTION). */
    public function actionId(): ?string
    {
        return self::text($this->data['actionId'] ?? null);
    }

    /** Card 3D Secure: where to send the buyer. */
    public function redirectPath(): ?string
    {
        return self::text($this->data['redirectPath'] ?? null);
    }

    /** The action opened by this call (OTP target, INPUT fields …), without secrets; null otherwise. */
    public function action(): ?PaymentAction
    {
        $action = $this->data['action'] ?? null;

        return is_array($action) ? new PaymentAction($action) : null;
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
