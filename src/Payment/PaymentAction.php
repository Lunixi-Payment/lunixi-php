<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * What the buyer must do to complete a payment (`data.action`). Present while
 * the payment waits for the buyer; confirm or resend with id().
 */
final class PaymentAction
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** `act_…` (card 3D Secure: `3ds_<paymentId>`). */
    public function id(): string
    {
        return (string) ($this->data['id'] ?? '');
    }

    /** One of the PaymentActionType::* values. */
    public function type(): string
    {
        return (string) ($this->data['type'] ?? '');
    }

    /** ISO 8601. */
    public function expiresAt(): string
    {
        return (string) ($this->data['expiresAt'] ?? '');
    }

    /** OTP: masked phone or address the code was sent to. */
    public function maskedTarget(): ?string
    {
        $target = $this->data['otp']['maskedTarget'] ?? null;

        return is_string($target) && $target !== '' ? $target : null;
    }

    /** Send `consentAccepted: true` with the confirm. */
    public function consentRequired(): bool
    {
        return ($this->data['consent']['required'] ?? false) === true;
    }

    public function consentUrl(): ?string
    {
        $url = $this->data['consent']['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** REDIRECT: the page to send the buyer to. */
    public function redirectUrl(): ?string
    {
        $url = $this->data['redirect']['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @return MethodInputField[] INPUT: the fields to send as confirm `fields`. */
    public function inputFields(): array
    {
        $fields = $this->data['input']['fields'] ?? [];

        return array_map(
            static fn ($field): MethodInputField => new MethodInputField(is_array($field) ? $field : []),
            array_values(is_array($fields) ? $fields : [])
        );
    }

    /**
     * Amount the buyer pays including fees and tax, minor units:
     * amount, feeTotal, taxTotal, buyerTotalCharge, currency.
     *
     * @return array<string,int|string>|null
     */
    public function charge(): ?array
    {
        $charge = $this->data['charge'] ?? null;

        return is_array($charge) ? $charge : null;
    }

    public function maskedBusinessName(): ?string
    {
        $name = $this->data['maskedBusinessName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** The provider asked for strong authentication (information only). */
    public function stepUpRequired(): bool
    {
        return ($this->data['stepUpRequired'] ?? false) === true;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
