<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

final class PaymentDecision
{
    public string $state;
    public string $paymentId;
    public string $chargedAmount;
    public string $refundedAmount;
    public string $capturedAmount;
    public string $gatewayStatus;
    public ?string $splitId;

    public function __construct(string $state, string $paymentId, string $chargedAmount, string $refundedAmount = '0', ?string $splitId = null, string $capturedAmount = '0', string $gatewayStatus = '')
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
