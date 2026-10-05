<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * One line of an ITEMIZED payment link.
 *
 * `itemKey` is the line's permanent identity — stock is counted on it — so it
 * cannot be changed once the link exists. Leave it out and the server assigns
 * one (`it_…`). Amounts are integers in minor units.
 *
 *   $item = (new PaymentLinkItem('Standard ticket', 45000))
 *       ->withItemKey('standard')
 *       ->withQuantityRange(0, 6)
 *       ->withCapacity(400);
 */
final class PaymentLinkItem
{
    /** @var array<string,mixed> */
    private array $payload;

    public function __construct(string $name, int $unitPriceMinor)
    {
        if (trim($name) === '') {
            throw new ConfigurationException("PaymentLinkItem 'name' is required.");
        }
        $this->payload = [
            'name' => $name,
            'unitPriceMinor' => CreatePaymentLinkRequest::positiveMinor($unitPriceMinor, 'unitPriceMinor'),
        ];
    }

    /** `[A-Za-z0-9_-]{1,64}`, unique within the link. */
    public function withItemKey(string $itemKey): self
    {
        if (trim($itemKey) === '') {
            throw new ConfigurationException("PaymentLinkItem 'itemKey' must not be empty.");
        }
        $this->payload['itemKey'] = $itemKey;
        return $this;
    }

    /** Plain text; shown as text, never as HTML. */
    public function withDescription(string $description): self
    {
        $this->payload['description'] = $description;
        return $this;
    }

    /** Buyer-selectable quantity for this line. A minimum of 0 makes the line optional. */
    public function withQuantityRange(int $minQuantity, int $maxQuantity): self
    {
        $this->payload['minQuantity'] = $minQuantity;
        $this->payload['maxQuantity'] = $maxQuantity;
        return $this;
    }

    /** Units of this line available across all payments; null = unlimited. */
    public function withCapacity(?int $capacity): self
    {
        $this->payload['capacity'] = $capacity;
        return $this;
    }

    /** Id (uuid) of a published payment-link image asset of your organisation; null = none. */
    public function withImageAssetId(?string $assetId): self
    {
        $this->payload['imageAssetId'] = CreatePaymentLinkRequest::assetIdOrNull($assetId, 'imageAssetId');
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
