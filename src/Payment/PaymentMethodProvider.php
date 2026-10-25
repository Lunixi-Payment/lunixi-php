<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A provider of a payment method (`methods[].providers[]` of listPaymentMethods()).
 * Send providerCode() as the block's `providerCode` when the method's
 * `providerSelection` is REQUIRED, or to pin a provider yourself on a
 * `providerSelectionMode` PLATFORM method.
 */
final class PaymentMethodProvider
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var MethodInputField[] */
    private array $inputFields;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->inputFields = array_map(
            static fn ($field): MethodInputField => new MethodInputField(is_array($field) ? $field : []),
            array_values(is_array($data['inputFields'] ?? null) ? $data['inputFields'] : [])
        );
    }

    public function providerCode(): string
    {
        return (string) ($this->data['providerCode'] ?? '');
    }

    public function displayName(): string
    {
        return (string) ($this->data['displayName'] ?? '');
    }

    public function logoUrl(): ?string
    {
        $url = $this->data['logoUrl'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @return string[] PaymentActionType::* values this provider can return. */
    public function actionTypes(): array
    {
        return self::strings($this->data['actionTypes'] ?? []);
    }

    /** @return string[] ISO 4217 codes; empty when the provider does not declare them. */
    public function currencies(): array
    {
        return self::strings($this->data['currencies'] ?? []);
    }

    /** Minor units; null = no limit. */
    public function minAmount(): ?int
    {
        return isset($this->data['minAmount']) ? (int) $this->data['minAmount'] : null;
    }

    /** Minor units; null = no limit. */
    public function maxAmount(): ?int
    {
        return isset($this->data['maxAmount']) ? (int) $this->data['maxAmount'] : null;
    }

    /** Whether `preauth: true` is accepted with this provider. */
    public function supportsPreauth(): bool
    {
        return ($this->data['preauth'] ?? false) === true;
    }

    /**
     * Whether this provider can be used in a split payment leg now: it supports
     * split payments and split payments with this method are enabled in the
     * merchant's form settings.
     */
    public function splitEligible(): bool
    {
        return ($this->data['splitEligible'] ?? false) === true;
    }

    /** SplitBehavior::* value; null when the provider cannot be used in a split payment. */
    public function splitBehavior(): ?string
    {
        $behavior = $this->data['splitBehavior'] ?? null;

        return is_string($behavior) && $behavior !== '' ? $behavior : null;
    }

    /** @return MethodInputField[] Fields to send in the method block. */
    public function inputFields(): array
    {
        return $this->inputFields;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * @param mixed $values
     * @return string[]
     */
    private static function strings($values): array
    {
        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }
}
