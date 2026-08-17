<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Result of creating a checkout intent. `token()` is the one-time, browser-safe
 * token the merchant hands to the embedded JS SDK to render and submit the form;
 * `paymentId()` is the intent id used for capture/refund/get and webhook matching.
 */
final class CheckoutIntent
{
    /** @var array<string,mixed> */
    private array $raw;

    /** @param array<string,mixed> $raw The decoded create-intent response. */
    public function __construct(array $raw)
    {
        // Gateway success envelope'u intent alanlarını `data` altında döndürür
        // ({status, code, data:{token, paymentId, ...}}); düz gövdeyi de destekle.
        // Üst-seviye status/code/message alanları hata raporlama için korunur.
        if (isset($raw['data']) && is_array($raw['data']) && (isset($raw['data']['token']) || isset($raw['data']['paymentId']))) {
            $raw = array_merge($raw, $raw['data']);
        }
        $this->raw = $raw;
    }

    public function paymentId(): string
    {
        return (string) ($this->raw['paymentId'] ?? '');
    }

    public function token(): string
    {
        return (string) ($this->raw['token'] ?? '');
    }

    /** SDK init script/HTML, when the gateway returns one (may be empty). */
    public function checkoutFormContent(): string
    {
        return (string) ($this->raw['checkoutFormContent'] ?? '');
    }

    public function success(): bool
    {
        return (bool) ($this->raw['success'] ?? false);
    }

    public function code(): string
    {
        return (string) ($this->raw['code'] ?? '');
    }

    public function message(): string
    {
        return (string) ($this->raw['message'] ?? '');
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->raw;
    }
}
