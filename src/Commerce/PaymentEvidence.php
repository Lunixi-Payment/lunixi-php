<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

/** Evaluates authenticated GET responses, NEVER browser fields or webhook status. */
final class PaymentEvidence
{
    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed>|null $split
     */
    public static function evaluate(OrderSnapshot $order, string $paymentId, array $response, ?array $split = null): PaymentDecision
    {
        $p = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
        foreach (['id' => $paymentId, 'merchantId' => $order->merchantId(), 'orderId' => $order->orderId(), 'currency' => $order->currency()] as $field => $expected) {
            if (($p[$field] ?? null) !== $expected) { throw new \RuntimeException('Payment identity mismatch: ' . $field); }
        }
        $amount = Money::integer($p['amount'] ?? null);
        $metadata = $p['metadata'] ?? null;
        if (!is_array($metadata) && is_string($p['metadataJson'] ?? null)) { $metadata = json_decode($p['metadataJson'], true); }
        // An installment fee increases finalAmount. Only accept that increase
        // when the authenticated intent echoes our persisted base-order hash.
        if ($amount < $order->amount() || ($amount !== $order->amount() && (!is_array($metadata) || ($metadata['lunixiCommerce']['fingerprint'] ?? null) !== $order->fingerprint()))) {
            throw new \RuntimeException('Payment amount does not match the persisted order.');
        }
        $status = $p['status'] ?? '';
        if (!is_string($status)) { throw new \RuntimeException('Payment status must be a string.'); }
        $ref = $p['split'] ?? null;
        if (is_array($ref) || $status === 'SETTLED_BY_SPLIT') {
            if (!is_array($ref) || ($ref['role'] ?? '') !== 'PARENT' || !is_string($ref['splitPaymentId'] ?? null) || $split === null) {
                throw new \RuntimeException('Authoritative split-parent evidence is required.');
            }
            $s = isset($split['data']) && is_array($split['data']) ? $split['data'] : $split;
            $a = $s['aggregate'] ?? [];
            if (($s['id'] ?? null) !== $ref['splitPaymentId'] || ($s['currency'] ?? null) !== $order->currency() || ($s['orderId'] ?? null) !== $order->orderId() || ($s['needsReconciliation'] ?? true) !== false || ($a['amountsBalanced'] ?? false) !== true || Money::integer($a['totalAmount'] ?? null) !== $amount || Money::integer($a['legsAmount'] ?? null) !== $amount) {
                throw new \RuntimeException('Split payment requires reconciliation.');
            }
            $captured = Money::integer($a['capturedAmount'] ?? null);
            $compensated = Money::integer($a['compensatedAmount'] ?? null);
            $refunded = Money::integer($s['refund']['refundedAmount'] ?? 0);
            $state = 'pending';
            if ($status === 'SETTLED_BY_SPLIT' && ($s['status'] ?? '') === 'COMPLETED' && $captured === $amount && $compensated === 0) { $state = $refunded === 0 ? 'paid' : ($refunded === $amount ? 'refunded' : 'partially_refunded'); }
            if (($s['status'] ?? '') === 'VOIDED' && $captured === 0 && $compensated === 0 && Money::integer($a['authorizedAmount'] ?? null) === 0) { $state = 'failed'; }
            if ($refunded > $captured) { throw new \RuntimeException('Invalid split refund balance.'); }
            return new PaymentDecision($state, $paymentId, $amount, $refunded, $ref['splitPaymentId'], $captured, $status);
        }
        $details = $response['details'] ?? $p['details'] ?? [];
        $captured = Money::integer($details['capturedAmount'] ?? null);
        $refunded = Money::integer($details['refundedAmount'] ?? null);
        if ($captured > $amount || $refunded > $captured) { throw new \RuntimeException('Invalid payment settlement balance.'); }
        $state = 'review';
        if ($status === 'CAPTURED' && $captured === $amount && $refunded === 0) { $state = 'paid'; }
        elseif ($status === 'PARTIALLY_CAPTURED' && $captured > 0 && $captured < $amount && $refunded === 0) { $state = 'partially_captured'; }
        elseif ($status === 'AUTHORIZED' && $captured === 0 && $refunded === 0) { $state = 'authorized'; }
        elseif ($status === 'REFUNDED' && $captured > 0 && $captured === $refunded) { $state = 'refunded'; }
        elseif ($status === 'PARTIALLY_REFUNDED' && $refunded > 0 && $refunded < $captured) { $state = 'partially_refunded'; }
        // FAILED alone does not prove definitive provider failure. The public
        // GET omits outcome certainty, so unknown/legacy failures stay on hold.
        elseif (in_array($status, ['EXPIRED', 'VOIDED'], true) && $captured === 0) { $state = 'failed'; }
        elseif (in_array($status, ['INITIATED', 'PROCESSING', 'AWAITING_3D'], true) && $captured === 0) { $state = 'pending'; }
        return new PaymentDecision($state, $paymentId, $amount, $refunded, null, $captured, $status);
    }
}
