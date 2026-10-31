<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/** Validates the opaque, token-bearing page URL returned by the authenticated API. */
final class PaymentPageUrl
{
    public static function validate(string $url): string
    {
        if (preg_match('#\Ahttps://pay\.lunixi\.com/payment/[A-Za-z0-9_.~-]{16,4096}\z#D', $url) !== 1) {
            throw new \UnexpectedValueException('The gateway returned an invalid paymentPageUrl.');
        }
        return $url;
    }
}
