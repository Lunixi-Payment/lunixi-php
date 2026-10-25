<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * How the Checkout Form opens a card leg's 3D Secure in a split payment
 * (panel: Form Settings → 3D Secure → 3D Secure display). Set in the panel,
 * not through the API; the form's split answers carry the value the payment
 * started with as `threeDsDisplay` (SplitPaymentResult::threeDsDisplay()).
 * Same values as the Node SDK's `ThreeDsDisplay`.
 */
final class ThreeDsDisplay
{
    /**
     * Default: in a window over the form. If the bank does not allow a window,
     * 3D Secure is restarted once on a separate page.
     */
    public const INLINE = 'INLINE';
    /**
     * On a separate page: the remaining steps run on the hosted continuation
     * page and the buyer returns to your `callbackUrl` at the end.
     */
    public const REDIRECT = 'REDIRECT';

    private function __construct()
    {
    }
}
