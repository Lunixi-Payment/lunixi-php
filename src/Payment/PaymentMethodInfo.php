<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A payment method open for the merchant (`methods[]` of listPaymentMethods()).
 */
final class PaymentMethodInfo
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var PaymentMethodProvider[] */
    private array $providers;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->providers = array_map(
            static fn ($provider): PaymentMethodProvider => new PaymentMethodProvider(is_array($provider) ? $provider : []),
            array_values(is_array($data['providers'] ?? null) ? $data['providers'] : [])
        );
    }

    /** One of the PaymentMethod::* codes. */
    public function paymentMethod(): string
    {
        return (string) ($this->data['paymentMethod'] ?? '');
    }

    public function category(): string
    {
        return (string) ($this->data['category'] ?? '');
    }

    /** Name of the method block in the request body (`mobileBilling`, `wallet`, `card`). */
    public function blockName(): string
    {
        return (string) ($this->data['blockName'] ?? '');
    }

    /** Label from the merchant's form settings. */
    public function displayName(): ?string
    {
        $name = $this->data['displayName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** One of the ProviderSelection::* values. */
    public function providerSelection(): string
    {
        return (string) ($this->data['providerSelection'] ?? '');
    }

    /** `providerCode` must be sent (the buyer picks the brand and more than one provider is connected). */
    public function requiresProviderCode(): bool
    {
        return $this->providerSelection() === ProviderSelection::REQUIRED;
    }

    /**
     * One of the ProviderSelectionMode::* values, the API behaviour: BUYER
     * needs `providerCode` when more than one provider is connected, PLATFORM
     * lets you leave it out and the platform picks. In the Checkout Form the
     * buyer always picks. Null when the response does not carry the field.
     */
    public function providerSelectionMode(): ?string
    {
        $mode = $this->data['providerSelectionMode'] ?? null;

        return is_string($mode) && $mode !== '' ? $mode : null;
    }

    /** @return string[] */
    public function actionTypes(): array
    {
        return is_array($this->data['actionTypes'] ?? null) ? array_values(array_map('strval', $this->data['actionTypes'])) : [];
    }

    /** @return string[] */
    public function currencies(): array
    {
        return is_array($this->data['currencies'] ?? null) ? array_values(array_map('strval', $this->data['currencies'])) : [];
    }

    public function minAmount(): ?int
    {
        return isset($this->data['minAmount']) ? (int) $this->data['minAmount'] : null;
    }

    public function maxAmount(): ?int
    {
        return isset($this->data['maxAmount']) ? (int) $this->data['maxAmount'] : null;
    }

    /** Whether at least one provider accepts `preauth: true`. */
    public function supportsPreauth(): bool
    {
        return ($this->data['preauth'] ?? false) === true;
    }

    /** Whether the method can be a split payment leg now (CARD: always). */
    public function splitEligible(): bool
    {
        return ($this->data['splitEligible'] ?? false) === true;
    }

    /**
     * Common SplitBehavior::* value of the method's providers (CARD:
     * HOLD_CAPTURE); null when no provider can be used or they differ (read
     * each provider's splitBehavior()).
     */
    public function splitBehavior(): ?string
    {
        $behavior = $this->data['splitBehavior'] ?? null;

        return is_string($behavior) && $behavior !== '' ? $behavior : null;
    }

    /**
     * @return PaymentMethodProvider[] Empty for CARD (routing picks the provider).
     *   In the order set in the merchant's form settings; a provider turned off
     *   only in the checkout form is still listed (the API accepts it).
     */
    public function providers(): array
    {
        return $this->providers;
    }

    public function provider(string $providerCode): ?PaymentMethodProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->providerCode() === $providerCode) {
                return $provider;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
