<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Buyer details (matches the gateway BuyerDto). Required: name, surname,
 * identityNumber, email, gsmNumber, city, country, zipCode, ip. Optional:
 * id, registrationDate, lastLoginDate, registrationAddress.
 */
final class Buyer
{
    private const REQUIRED = [
        'name', 'surname', 'identityNumber', 'email', 'gsmNumber', 'city', 'country', 'zipCode', 'ip',
    ];
    private const OPTIONAL = ['id', 'registrationDate', 'lastLoginDate', 'registrationAddress'];

    /** @var array<string,string> */
    private array $data;

    /**
     * @param array<string,string> $data
     * @throws ConfigurationException
     */
    public function __construct(array $data)
    {
        $out = [];
        foreach (self::REQUIRED as $key) {
            if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
                throw new ConfigurationException("Buyer '{$key}' is required.");
            }
            $out[$key] = (string) $data[$key];
        }
        foreach (self::OPTIONAL as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $out[$key] = (string) $data[$key];
            }
        }
        $this->data = $out;
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return $this->data;
    }
}
