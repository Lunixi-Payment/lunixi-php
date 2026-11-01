<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

/** Validates known wire money members without casting or rewriting signed payloads. */
final class MoneyPayload
{
    private const FIELDS = [
        'amount', 'amountMinor', 'price', 'priceAmount', 'paidAmount', 'totalAmount', 'subtotalAmount', 'taxAmount', 'amountPaid',
        'legAmount', 'chargeAmount', 'capturedAmount', 'refundedAmount', 'authorizedAmount', 'compensatedAmount',
        'requestedAmount', 'refundableAmount', 'nonRefundableAmount', 'disputedAmount', 'legsAmount',
        'minAmount', 'maxAmount', 'minAmountMinor', 'maxAmountMinor', 'unitPriceMinor', 'collectedAmountMinor',
        'premiumAmount', 'collectedPremiumAmount', 'inForcePremiumAmount', 'cancelledPremiumAmount',
        'refundedPremiumAmount', 'refundOpenPremiumAmount', 'insuranceValueAmount', 'priceSnapshotAmount',
        'sourceAmountMinor', 'targetAmountMinor', 'taxAmountMinor', 'balanceMinor', 'balanceAfterMinor',
        'availableMinor', 'blockedMinor', 'availableWithoutOverdraftMinor', 'overdraftAvailableMinor',
        'sourceBalanceAfterMinor', 'targetBalanceAfterMinor', 'balance', 'availableBalance', 'blockedBalance',
        'feeAmount', 'feeAmountMinor', 'fixedAmountMinor', 'minTransactionMinor', 'commissionAmountMinor', 'payoutAmountMinor', 'settlementAmountMinor', 'netAmountMinor', 'grossAmountMinor', 'fixedAmount', 'fixedFee', 'fixedFeeMinor', 'mtdVolumeMinor',
        'orderAmountMinor', 'capturedAmountMinor', 'cumulativeCapturedMinor', 'remainingCapturableMinor',
        'refundAmountMinor', 'cumulativeRefundedMinor', 'remainingRefundableMinor', 'netAmount', 'grossAmount',
    ];

    /** Closed schema containers; custom fields, metadata and provider bodies are opaque. */
    private const CONTAINERS = [
        'data', 'items', 'legs', 'steps', 'pendingSteps', 'schedule', 'promissoryNote', 'notes',
        'aggregate', 'refund', 'dispute', 'details', 'payment', 'basketItems', 'sellers', 'scenario',
        'counters', 'balances', 'totals', 'orders', 'policies', 'options', 'quote', 'order', 'transfer',
        'commission', 'commissionRule', 'fee', 'settlement', 'payout', 'context', 'insuranceData', 'tickets', 'stay', 'device', 'product', 'vehicle', 'trip', 'rental',
        'instrument', 'event', 'accident', 'procedure', 'pet', 'residence', 'abroadStay', 'property', 'health',
    ];

    /** Only routes whose public contracts define these money members. */
    public static function supports(string $path): bool
    {
        return preg_match('~^/api/v1/(?:payments|payment-links|marketplace|subscriptions|subscription|wallet|bank-connect|insurance|fraud)(?:/|$)|^/api/v1/bin/installments$~D', $path) === 1;
    }

    /** Operation amount absence is deliberate; explicit null must never become a full capture/refund. @param mixed $payload */
    public static function validateOperationAmount(string $method, string $path, $payload): void
    {
        if (strtoupper($method) !== 'POST' || preg_match('~^/api/v1/payments/(?:[^/]+/(?:capture|refund|refund-to-wallet)|split/[^/]+/refund)$~D', $path) !== 1) { return; }
        $members = $payload instanceof \stdClass ? get_object_vars($payload) : $payload;
        if (is_array($members) && array_key_exists('amount', $members)) { Money::positive($members['amount']); }
    }

    /** @param mixed $payload */
    public static function validate($payload): void
    {
        if (!is_array($payload) && !$payload instanceof \stdClass) { return; }
        foreach ($payload as $key => $value) {
            if ($value === null) { continue; }
            if (in_array($key, self::FIELDS, true)) {
                Money::canonical($value, stripos($key, 'balance') !== false || in_array($key, ['availableMinor', 'availableWithoutOverdraftMinor'], true));
            } elseif ($key === 'suggestedAmountsMinor') {
                if (!is_array($value)) { throw new \InvalidArgumentException('suggestedAmountsMinor must be a list.'); }
                foreach ($value as $amount) { Money::positive($amount); }
            } elseif (is_int($key) || in_array($key, self::CONTAINERS, true)) { self::validate($value); }
        }
    }
}
