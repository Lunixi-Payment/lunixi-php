<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for POST /api/v1/payments/{paymentId}/actions/{actionId}/confirm, sent
 * by the merchant server (PaymentClient::confirmPaymentAction()):
 *
 *   - otp($code): the code the buyer entered (`action.type = OTP`);
 *   - withConsentAccepted(true): required when `action.consent.required` is true;
 *   - fields($values): the values an INPUT action asks for (`action.input.fields`).
 *
 * The server confirms with its signature. The checkout token fields (`token`,
 * `checkoutSessionProof`) belong to the hosted form and are never sent here.
 */
final class PaymentActionConfirmRequest
{
    /** @var array<string,mixed> */
    private array $data = [];

    private function __construct()
    {
    }

    /** The code the buyer entered, digits only. */
    public static function otp(string $code): self
    {
        if (preg_match('/^[0-9]{1,16}$/', $code) !== 1) {
            throw new ConfigurationException('otp must be the code the buyer entered, digits only.');
        }
        $request = new self();
        $request->data['otp'] = $code;

        return $request;
    }

    /**
     * Values of an INPUT action, keyed by `action.input.fields[].key`.
     *
     * @param array<string,string|int|float|bool> $fields
     */
    public static function fields(array $fields): self
    {
        if ($fields === []) {
            throw new ConfigurationException('fields must carry at least one value.');
        }
        $request = new self();
        $request->data['fields'] = PaymentMethod::requireFields($fields, 'fields');

        return $request;
    }

    /** Agreement consent only (an action whose only step is the consent). */
    public static function consent(): self
    {
        return (new self())->withConsentAccepted(true);
    }

    public function withConsentAccepted(bool $accepted): self
    {
        $this->data['consentAccepted'] = $accepted;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
