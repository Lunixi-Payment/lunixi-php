<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

final class PaymentDecision
{
    /** A NEW captured payment for the same order (e.g. a late bank transfer accepted by the platform). */
    public const REVIEW_LINKED_PAYMENT = 'linked_payment';
    /** A payment the native order no longer accepts (e.g. cancelled before the money arrived). Set by adapters. */
    public const REVIEW_ORDER_CLOSED = 'order_closed';

    public string $state;
    public string $paymentId;
    public string $chargedAmount;
    public string $refundedAmount;
    public string $capturedAmount;
    public string $gatewayStatus;
    public ?string $splitId;
    /** Why a `review` decision needs the merchant; null for ordinary decisions. */
    public ?string $reviewReason;

    public function __construct(string $state, string $paymentId, string $chargedAmount, string $refundedAmount = '0', ?string $splitId = null, string $capturedAmount = '0', string $gatewayStatus = '', ?string $reviewReason = null)
    {
        $this->state = $state;
        $this->paymentId = $paymentId;
        $this->chargedAmount = $chargedAmount;
        $this->refundedAmount = $refundedAmount;
        $this->splitId = $splitId;
        $this->capturedAmount = $capturedAmount;
        $this->gatewayStatus = $gatewayStatus;
        $this->reviewReason = $reviewReason;
    }
    public function isPaid(): bool { return $this->state === 'paid'; }
    public function isLinkedPayment(): bool { return $this->reviewReason === self::REVIEW_LINKED_PAYMENT; }
}
