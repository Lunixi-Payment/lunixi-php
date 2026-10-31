<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

final class PaymentDecision
{
    public string $state;
    public string $paymentId;
    public int $chargedAmount;
    public int $refundedAmount;
    public int $capturedAmount;
    public string $gatewayStatus;
    public ?string $splitId;

    public function __construct(string $state, string $paymentId, int $chargedAmount, int $refundedAmount = 0, ?string $splitId = null, int $capturedAmount = 0, string $gatewayStatus = '')
    {
        $this->state = $state;
        $this->paymentId = $paymentId;
        $this->chargedAmount = $chargedAmount;
        $this->refundedAmount = $refundedAmount;
        $this->splitId = $splitId;
        $this->capturedAmount = $capturedAmount;
        $this->gatewayStatus = $gatewayStatus;
    }
    public function isPaid(): bool { return $this->state === 'paid'; }
}
