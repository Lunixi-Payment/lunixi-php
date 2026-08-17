<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * Builds the body for `POST /fraud/decisions/evaluate`. Every field is optional —
 * supply as much context as you have (the more signals, the better the decision).
 * Sub-objects (payment, customer, device, telemetry) are passed as arrays so the
 * SDK stays forward-compatible with the engine's evolving signal set.
 *
 *   $req = (new EvaluateRequest())
 *       ->withSubjectType('card_payment')
 *       ->withProductContext('payment_form')
 *       ->withPayment(['amount' => 25000, 'currency' => 'TRY', 'is3D' => false])
 *       ->withCustomer(['email' => 'a@b.com', 'country' => 'TR', 'isNew' => true])
 *       ->withDevice(['ipAddress' => $ip, 'vpnDetected' => false]);
 */
final class EvaluateRequest
{
    /** @var array<string,mixed> */
    private array $body = [];

    public function withSubjectType(string $subjectType): self
    {
        $this->body['subjectType'] = $subjectType;
        return $this;
    }

    public function withProductContext(string $productContext): self
    {
        $this->body['productContext'] = $productContext;
        return $this;
    }

    public function withIntegrationMode(string $mode): self
    {
        $this->body['integrationMode'] = $mode;
        return $this;
    }

    public function withPaymentRail(string $rail): self
    {
        $this->body['paymentRail'] = $rail;
        return $this;
    }

    public function withTransferDirection(string $direction): self
    {
        $this->body['transferDirection'] = $direction;
        return $this;
    }

    public function withPaymentId(string $paymentId): self
    {
        $this->body['paymentId'] = $paymentId;
        return $this;
    }

    /** @param array<string,mixed> $payment */
    public function withPayment(array $payment): self
    {
        $this->body['payment'] = $payment;
        return $this;
    }

    /** @param array<string,mixed> $customer */
    public function withCustomer(array $customer): self
    {
        $this->body['customer'] = $customer;
        return $this;
    }

    /** @param array<string,mixed> $device */
    public function withDevice(array $device): self
    {
        $this->body['device'] = $device;
        return $this;
    }

    /** @param array<string,mixed> $telemetry */
    public function withTelemetry(array $telemetry): self
    {
        $this->body['telemetry'] = $telemetry;
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->body['metadata'] = $metadata;
        return $this;
    }

    public function withIdentityAssertion(string $jwt): self
    {
        $this->body['identityAssertion'] = $jwt;
        return $this;
    }

    /** @return array<string,mixed> The request body for the gateway. */
    public function toArray(): array
    {
        return $this->body;
    }
}
