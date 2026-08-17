<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/** Body for POST /api/v1/bin/installments. */
final class InstallmentOptionsRequest
{
    /** @var array<string,mixed> */
    private array $data = [];

    public function __construct(?int $amount = null, ?string $currency = null, ?string $intentId = null)
    {
        if ($amount !== null) {
            if ($amount < 1) {
                throw new ConfigurationException("Installment amount must be a positive minor-unit integer.");
            }
            $this->data['amount'] = $amount;
        }
        if ($currency !== null && $currency !== '') {
            $this->data['currency'] = strtoupper($currency);
        }
        if ($intentId !== null && $intentId !== '') {
            $this->data['intentId'] = $intentId;
        }
        if (!isset($this->data['amount']) && !isset($this->data['intentId'])) {
            throw new ConfigurationException("InstallmentOptionsRequest requires amount or intentId.");
        }
    }

    public function withBinOrPan(string $binOrPan): self
    {
        $this->data['binOrPan'] = $binOrPan;
        return $this;
    }

    public function withPaymentMethod(string $method): self
    {
        $this->data['paymentMethod'] = $method;
        return $this;
    }

    public function withInstallment(int $installment): self
    {
        $this->data['installment'] = max(1, $installment);
        return $this;
    }

    public function withFormat(string $format): self
    {
        $this->data['format'] = $format;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
