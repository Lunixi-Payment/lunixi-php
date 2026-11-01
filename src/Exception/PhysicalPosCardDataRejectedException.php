<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * A physical POS request carried card data (PCI). Card data is never accepted
 * over the API: the card is read on the device.
 *
 * Thrown in two places, both with the same code
 * (`physical_pos.payment.card_data_rejected`):
 *   - by the SDK before anything is sent, when a request or filter key looks
 *     like card data (`pan`, `cardNumber`, `cvv`, `expiryDate`, `track2` …).
 *     {@see getStatusCode()} is then 0;
 *     The same happens when a free text value holds a card number (13–19
 *     digits passing Luhn, also space / dash separated): `merchantReference`,
 *     `items[i].name`, `items[i].taxGroupCode` and the `merchantReference` /
 *     `q` filters are scanned like the gateway scans them;
 *   - by the gateway (HTTP 400), for a key or for a card number inside a free
 *     text field.
 *
 * {@see getField()} is the field PATH only (`items[1].cvv`, `target.pan`,
 * `(body)`); the value is never echoed, logged or stored.
 */
class PhysicalPosCardDataRejectedException extends ApiException
{
    public const CODE = 'physical_pos.payment.card_data_rejected';

    private ?string $field;

    /**
     * @param array<string,mixed>|null  $responseBody
     * @param array<string,string>|null $responseHeaders
     */
    public function __construct(
        string $message,
        ?string $field,
        int $statusCode = 0,
        ?array $responseBody = null,
        ?string $requestId = null,
        ?\Throwable $previous = null,
        ?array $responseHeaders = null
    ) {
        parent::__construct($message, $statusCode, self::CODE, $responseBody, $requestId, $previous, $responseHeaders);
        $this->field = $field;
    }

    /** Path of the rejected field, never its value. */
    public function getField(): ?string
    {
        return $this->field;
    }

    /** True when the SDK refused the request before sending it. */
    public function isClientSide(): bool
    {
        return $this->getStatusCode() === 0;
    }
}
