<?php
declare(strict_types=1);
namespace Lunixi\Sdk\Commerce;

final class CheckoutSession
{
    public string $paymentId;
    public string $token;
    public function __construct(string $paymentId, string $token) { $this->paymentId = $paymentId; $this->token = $token; }
    public function hostedUrl(): string { return 'https://pay.lunixi.com/payment/' . rawurlencode($this->token); }
    /** Safe inline JSON for the fixed official checkout bundle; never render gateway HTML. */
    public function browserConfig(): string
    {
        return json_encode(['type' => 'payment', 'target' => 'lunixi-checkout-form', 'options' => ['token' => $this->token]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
