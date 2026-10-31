<?php
declare(strict_types=1);
namespace Lunixi\Sdk\Commerce;

use Lunixi\Sdk\Payment\PaymentPageUrl;

final class CheckoutSession
{
    public string $paymentId;
    public string $token;
    public string $paymentPageUrl;
    public function __construct(string $paymentId, string $token, string $paymentPageUrl = '')
    {
        $this->paymentId = $paymentId;
        $this->token = $token;
        $this->paymentPageUrl = $paymentPageUrl === '' ? '' : PaymentPageUrl::validate($paymentPageUrl);
    }
    /** Never construct a hosted URL from the embedded token, which has another origin binding. */
    public function hostedUrl(): string
    {
        if ($this->paymentPageUrl === '') {
            throw new \RuntimeException('The gateway did not return paymentPageUrl; use embedded checkout or reconcile the existing attempt.');
        }
        return PaymentPageUrl::validate($this->paymentPageUrl);
    }
    /** Safe inline JSON for the fixed official checkout bundle; never render gateway HTML. */
    public function browserConfig(): string
    {
        return json_encode(['type' => 'payment', 'target' => 'lunixi-checkout-form', 'options' => ['token' => $this->token]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
