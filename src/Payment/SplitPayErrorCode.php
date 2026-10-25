<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Error codes of split payments (ApiException::getErrorCode(); docs:
 * getting-started/errors#<code>). Same values as the Node SDK's
 * `SplitPayErrorCode`.
 */
final class SplitPayErrorCode
{
    /** 422 — split payments with non-card methods are disabled in your form settings. */
    public const METHODS_DISABLED = 'split_pay.validation.methods_disabled';
    /** 422 — the leg's method is not enabled for split payments. */
    public const METHOD_NOT_ALLOWED = 'split_pay.validation.method_not_allowed';
    /** 422 — the provider is not available for the method. */
    public const PROVIDER_NOT_AVAILABLE = 'split_pay.validation.provider_not_available';
    /** 422 — the provider cannot be used in a split payment (`splitEligible: false`). */
    public const PROVIDER_NOT_SPLIT_ELIGIBLE = 'split_pay.validation.provider_not_split_eligible';
    /** 422 — a provider appears in more than one leg. */
    public const DUPLICATE_PROVIDER = 'split_pay.validation.duplicate_provider';
    /** 422 — more than one non-reversible leg. */
    public const MULTIPLE_IRREVERSIBLE_LEGS = 'split_pay.validation.multiple_irreversible_legs';
    /** 422 — a leg is below your minimum leg amount (`details.minLegAmount`). */
    public const LEG_BELOW_MINIMUM = 'split_pay.validation.leg_below_minimum';
    /** 422 — a leg is outside the provider's limits (`details.minAmount`, `details.maxAmount`). */
    public const LEG_OUTSIDE_PROVIDER_LIMITS = 'split_pay.validation.leg_outside_provider_limits';
    /** 422 — too many card legs (`details.max`). */
    public const CARD_LEG_COUNT_OUT_OF_RANGE = 'split_pay.validation.card_leg_count_out_of_range';
    /** 422 — invalid installment count of a leg (`details.legIndex`, `details.max`). */
    public const INVALID_INSTALLMENT = 'split_pay.validation.invalid_installment';
    /** 422 — the installment quote of a card leg cannot be used (`details.legIndex`, `details.reason`). */
    public const INSTALLMENT_QUOTE_REJECTED = 'split_pay.validation.installment_quote_rejected';
    /** 422 — the legs do not add up to `totalAmount`. */
    public const AMOUNT_MISMATCH = 'split_pay.validation.amount_mismatch';
    /** 422 — too few or too many legs (`details.min`, `details.max`). */
    public const LEG_COUNT_OUT_OF_RANGE = 'split_pay.validation.leg_count_out_of_range';
    /** 422 — a leg amount is not a positive integer. */
    public const INVALID_LEG_AMOUNT = 'split_pay.validation.invalid_leg_amount';
    /** 422 — a leg's currency differs from the payment, or the provider does not support it. */
    public const CURRENCY_MISMATCH = 'split_pay.validation.currency_mismatch';
    /** 422 — a card leg without a card, or a non-card leg with one (`details.index`). */
    public const MISSING_CARD_TOKEN = 'split_pay.validation.missing_card_token';
    /** 409 — the step cannot be taken now (`details.reason`). */
    public const STEP_NOT_ALLOWED = 'split_pay.step.not_allowed';
    /** 409 — the split payment is being processed; try again in a few seconds. */
    public const STEP_BUSY = 'split_pay.step.busy';
    /** 410 — the continuation link can no longer be used (`details.reason`: INVALID, EXPIRED, CLOSED). */
    public const CONTINUATION_UNAVAILABLE = 'split_pay.continuation.unavailable';
    /** 410 — the continuation page link is invalid or has expired (`details.reason`: INVALID, EXPIRED). */
    public const RESUME_UNAVAILABLE = 'split_pay.resume.unavailable';
    /** 409 — the continuation page is already open in another window. */
    public const RESUME_ALREADY_OPENED = 'split_pay.resume.already_opened';
    /** 401 — the continuation page session could not be verified. */
    public const RESUME_PROOF_INVALID = 'split_pay.resume.proof_invalid';
    /** 503 — split payment cannot be started right now; nothing was started and no money moved. */
    public const RESUME_NOT_CONFIGURED = 'split_pay.resume.not_configured';
    /** 409 — 3D Secure of this card leg was already restarted once on a separate page. */
    public const THREE_DS_RESTART_EXHAUSTED = 'split_pay.three_ds.restart_exhausted';
    /**
     * 410 — the 3D Secure session of this card leg is closed or has expired; no restart is made and the
     * buyer is asked to enter the card again (`details.reason`: THREE_DS_CLOSED, THREE_DS_SESSION_EXPIRED).
     */
    public const THREE_DS_RESTART_EXPIRED = 'split_pay.three_ds.restart_expired';
    /** 409 — the order is not open for a split payment (Checkout Form; `details.reason`). */
    public const CHECKOUT_ORDER_NOT_OPEN = 'split_pay.checkout.order_not_open';
    /**
     * 422 — insurance is selected in the Checkout Form but the split payment has no card leg; the premium
     * is collected from a card leg (`details.canContinueWithoutInsurance`). Card legs of an insured split
     * payment are single-payment (no installments).
     */
    public const INSURANCE_REQUIRES_CARD_LEG = 'split_pay.insurance.requires_card_leg';
    /** 400 — the refund exceeds the refundable amount (`details.refundableAmount`, `details.nonRefundableAmount`). */
    public const REFUND_AMOUNT_EXCEEDED = 'split_pay.refund.amount_exceeded';
    /** 422 — the refund cannot be split across the legs (non-card legs are refunded only in full). */
    public const REFUND_AMOUNT_NOT_DISTRIBUTABLE = 'split_pay.refund.amount_not_distributable';
    /** 409 — a refund is not possible now (`details.reason`: SPLIT_NOT_COMPLETED, SPLIT_IN_PROGRESS, COMPENSATION_REFUND_PENDING). */
    public const REFUND_NOT_ALLOWED = 'split_pay.refund.not_allowed';
    /** Some legs could not be refunded; retry with the same Idempotency-Key. */
    public const REFUND_INCOMPLETE = 'split_pay.refund.incomplete';

    private function __construct()
    {
    }
}
