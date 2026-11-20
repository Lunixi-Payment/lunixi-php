<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Buyer details (matches the gateway BuyerDto). Required: name, surname,
 * identityNumber, email, gsmNumber, city, country, zipCode. Optional: id,
 * registrationDate, lastLoginDate, registrationAddress and ip.
 *
 * `ip` (the buyer's browser IP) is required by every payment endpoint except
 * the checkout intents (createIntent(), createMarketplaceIntent()): there the
 * buyer pays on the Lunixi form, which takes the IP from the buyer's browser.
 * OrderDetails checks it per endpoint.
 */
final class Buyer
{
    private const REQUIRED = [
        'name', 'surname', 'identityNumber', 'email', 'gsmNumber', 'city', 'country', 'zipCode',
    ];
    private const OPTIONAL = ['id', 'registrationDate', 'lastLoginDate', 'registrationAddress', 'ip'];

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
