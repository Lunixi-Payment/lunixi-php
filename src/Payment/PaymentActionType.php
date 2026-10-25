<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * What the buyer must do when a payment response carries `action` (`action.type`).
 */
final class PaymentActionType
{
    /** Send the one-time code with PaymentClient::confirmPaymentAction(). */
    public const OTP = 'OTP';
    /** Send the buyer to `action.redirect.url` (for cards: the bank's 3D Secure page). */
    public const REDIRECT = 'REDIRECT';
    /** Show the buyer something to act on outside the page (transfer details, a code). */
    public const DISPLAY = 'DISPLAY';
    /** The buyer approves in the provider's app; the result arrives by webhook. */
    public const APP_APPROVAL = 'APP_APPROVAL';
    /** Send the fields listed in `action.input.fields` with PaymentClient::confirmPaymentAction(). */
    public const INPUT = 'INPUT';

    private function __construct()
    {
    }
}
