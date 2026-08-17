<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Billing/shipping address (matches the gateway AddressDto). All fields required.
 */
final class Address
{
    private const REQUIRED = ['address', 'zipCode', 'contactName', 'city', 'country'];

    /** @var array<string,string> */
    private array $data;

    /**
     * @param array<string,string> $data Keys: address, zipCode, contactName, city, country.
     * @throws ConfigurationException
     */
    public function __construct(array $data)
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
                throw new ConfigurationException("Address '{$key}' is required.");
            }
        }
        $this->data = [
            'address' => (string) $data['address'],
            'zipCode' => (string) $data['zipCode'],
            'contactName' => (string) $data['contactName'],
            'city' => (string) $data['city'],
            'country' => (string) $data['country'],
        ];
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return $this->data;
    }
}
