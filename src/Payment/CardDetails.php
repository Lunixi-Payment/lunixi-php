<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Gateway CardDetailsDto. Raw PAN fields are for direct API only (PCI SAQ-D).
 * Hosted/secure-field flows should pass secureCardToken or stored-card fields.
 */
final class CardDetails
{
    /** @var array<string,mixed> */
    private array $data = [];

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        foreach ([
            'cardHolderName', 'cardNumber', 'expireMonth', 'expireYear', 'cvcNumber',
            'cardUserKey', 'cardToken', 'consumerToken', 'publicCardStorageToken',
            'cardName', 'secureCardToken',
        ] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $this->data[$key] = (string) $data[$key];
            }
        }
        if (isset($data['cardSave'])) {
            $this->data['cardSave'] = (bool) $data['cardSave'];
        }

        $hasRawCard = isset($this->data['cardNumber']);
        $hasStoredCard = isset($this->data['cardToken']) || isset($this->data['secureCardToken'])
            || isset($this->data['consumerToken']) || isset($this->data['publicCardStorageToken']);
        if (!$hasRawCard && !$hasStoredCard) {
            throw new ConfigurationException("CardDetails requires a raw cardNumber or a card token.");
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
