<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for `POST /api/v1/subscriptions/cards`. The card is referenced by the
 * tokenised `paymentMethodToken` produced by the browser checkout SDK — the
 * plugin/back-end never handles the PAN (PCI SAQ-A). Display metadata
 * (brand/last4/expiry) is stored for merchant UI only.
 */
final class AddCardRequest
{
    public const VERIFY_NONE = 'NONE';
    public const VERIFY_ON_ADD = 'ON_ADD';
    public const VERIFY_FIRST_CHARGE = 'FIRST_CHARGE';

    private string $customerId;
    private string $paymentMethodToken;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(string $customerId, string $paymentMethodToken)
    {
        if (trim($customerId) === '') {
            throw new ConfigurationException("AddCardRequest 'customerId' is required.");
        }
        if (trim($paymentMethodToken) === '') {
            throw new ConfigurationException("AddCardRequest 'paymentMethodToken' is required.");
        }
        $this->customerId = $customerId;
        $this->paymentMethodToken = $paymentMethodToken;
    }

    public function withBrand(string $brand): self
    {
        $this->optional['brand'] = $brand;
        return $this;
    }

    public function withLast4(string $last4): self
    {
        $this->optional['last4'] = $last4;
        return $this;
    }

    public function withBin(string $bin): self
    {
        $this->optional['bin'] = $bin;
        return $this;
    }

    public function withExpiry(int $month, int $year): self
    {
        $this->optional['expireMonth'] = $month;
        $this->optional['expireYear'] = $year;
        return $this;
    }

    public function withCardholderName(string $name): self
    {
        $this->optional['cardholderName'] = $name;
        return $this;
    }

    public function withIsDefault(bool $isDefault): self
    {
        $this->optional['isDefault'] = $isDefault;
        return $this;
    }

    public function withNickname(string $nickname): self
    {
        $this->optional['nickname'] = $nickname;
        return $this;
    }

    public function withVerificationMode(string $mode): self
    {
        if (!in_array($mode, [self::VERIFY_NONE, self::VERIFY_ON_ADD, self::VERIFY_FIRST_CHARGE], true)) {
            throw new ConfigurationException("Unsupported card verificationMode '{$mode}'.");
        }
        $this->optional['verificationMode'] = $mode;
        return $this;
    }

    /** @param string[] $currencies */
    public function withSupportedCurrencies(array $currencies): self
    {
        $this->optional['supportedCurrencies'] = array_values(array_map('strtoupper', $currencies));
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge([
            'customerId' => $this->customerId,
            'paymentMethodToken' => $this->paymentMethodToken,
        ], $this->optional);
    }
}
