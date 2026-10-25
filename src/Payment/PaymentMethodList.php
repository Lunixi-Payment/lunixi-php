<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Response of GET /api/v1/payments/methods: the payment methods open for the
 * merchant (enabled in the form settings, with at least one provider connected).
 */
final class PaymentMethodList
{
    /** @var PaymentMethodInfo[] */
    private array $methods;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->methods = array_map(
            static fn ($method): PaymentMethodInfo => new PaymentMethodInfo(is_array($method) ? $method : []),
            array_values(is_array($data['methods'] ?? null) ? $data['methods'] : [])
        );
    }

    /** @return PaymentMethodInfo[] */
    public function methods(): array
    {
        return $this->methods;
    }

    /** The method with this code (`QR` finds `QR_CODE`), or null when it is not open. */
    public function find(string $paymentMethod): ?PaymentMethodInfo
    {
        $code = PaymentMethod::normalize($paymentMethod);
        foreach ($this->methods as $method) {
            if ($method->paymentMethod() === $code) {
                return $method;
            }
        }

        return null;
    }
}
