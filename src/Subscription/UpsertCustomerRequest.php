<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for `PUT /api/v1/subscriptions/customers` (idempotent upsert keyed by
 * `externalId` — the merchant's own customer key, e.g. a WordPress user id).
 *
 * Renewals and collection attempts take the buyer from this record, so the
 * identity, contact and billing address are required (the gateway refuses a
 * missing one with 400 `subscription.customer.field_required`). The billing
 * contact defaults to the customer's full name; the shipping address is optional.
 */
final class UpsertCustomerRequest
{
    /** Fields the gateway requires besides `externalId`. */
    public const REQUIRED_FIELDS = [
        'name', 'surname', 'email', 'gsmNumber', 'identityNumber',
        'billingAddress', 'billingCity', 'billingCountry', 'billingZipCode',
    ];

    private string $externalId;

    /** @var array<string,string> */
    private array $fields = [];

    public function __construct(string $externalId)
    {
        if (trim($externalId) === '') {
            throw new ConfigurationException("UpsertCustomerRequest 'externalId' is required.");
        }
        $this->externalId = $externalId;
    }

    public function withName(string $name): self
    {
        $this->fields['name'] = $name;
        return $this;
    }

    public function withSurname(string $surname): self
    {
        $this->fields['surname'] = $surname;
        return $this;
    }

    public function withEmail(string $email): self
    {
        $this->fields['email'] = $email;
        return $this;
    }

    public function withPhone(string $gsmNumber): self
    {
        $this->fields['gsmNumber'] = $gsmNumber;
        return $this;
    }

    /** Turkish ID number or passport number. */
    public function withIdentityNumber(string $identityNumber): self
    {
        $this->fields['identityNumber'] = $identityNumber;
        return $this;
    }

    /** The billing address; `$contactName` empty = the customer's full name. */
    public function withBillingAddress(string $address, string $city, string $country, string $zipCode, string $contactName = ''): self
    {
        $this->fields['billingAddress'] = $address;
        $this->fields['billingCity'] = $city;
        $this->fields['billingCountry'] = $country;
        $this->fields['billingZipCode'] = $zipCode;
        if ($contactName !== '') {
            $this->fields['billingContactName'] = $contactName;
        }
        return $this;
    }

    public function withShippingAddress(string $address, string $city, string $country, string $zipCode, string $contactName = ''): self
    {
        $this->fields['shippingAddress'] = $address;
        $this->fields['shippingCity'] = $city;
        $this->fields['shippingCountry'] = $country;
        $this->fields['shippingZipCode'] = $zipCode;
        if ($contactName !== '') {
            $this->fields['shippingContactName'] = $contactName;
        }
        return $this;
    }

    /**
     * @return array<string,string>
     * @throws ConfigurationException when a required field is missing (nothing is sent).
     */
    public function toArray(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_FIELDS,
            fn (string $field): bool => trim($this->fields[$field] ?? '') === ''
        ));
        if ($missing !== []) {
            throw new ConfigurationException('A subscription customer requires ' . implode(', ', $missing) . '.');
        }

        return array_merge(['externalId' => $this->externalId], $this->fields);
    }
}
