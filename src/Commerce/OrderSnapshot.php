<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

use Lunixi\Sdk\Payment\Currency;

/** Immutable order identity persisted before any financial API call. */
final class OrderSnapshot
{
    private string $merchantId;
    private string $orderId;
    private string $currency;
    private int $amount;

    public function __construct(string $merchantId, string $orderId, int $amount, string $currency)
    {
        if (trim($merchantId) === '' || trim($orderId) === '' || strlen($orderId) > 128 || $amount < 1 || $currency !== strtoupper($currency) || !Currency::isSupported($currency)) {
            throw new \InvalidArgumentException('Merchant, order, positive amount and supported uppercase currency are required.');
        }
        Money::integer($amount);
        $this->merchantId = $merchantId;
        $this->orderId = $orderId;
        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function merchantId(): string { return $this->merchantId; }
    public function orderId(): string { return $this->orderId; }
    public function currency(): string { return $this->currency; }
    public function amount(): int { return $this->amount; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['merchantId' => $this->merchantId, 'orderId' => $this->orderId, 'amount' => $this->amount, 'currency' => $this->currency];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['merchantId'], (string) $data['orderId'], Money::integer($data['amount']), (string) $data['currency']);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
