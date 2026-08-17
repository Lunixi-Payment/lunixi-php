<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * A basket line item (matches the gateway BasketItemDto). `price` is in minor
 * units (e.g. 1000 = 10.00). Required: id, price, name, category1, itemType.
 */
final class BasketItem
{
    public const TYPE_PHYSICAL = 'PHYSICAL';
    public const TYPE_VIRTUAL = 'VIRTUAL';

    /** @var array<string,mixed> */
    private array $data;

    /**
     * @param array<string,mixed> $data Keys: id, price(int minor), name, category1, itemType, category2?
     * @throws ConfigurationException
     */
    public function __construct(array $data)
    {
        foreach (['id', 'name', 'category1', 'itemType'] as $key) {
            if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
                throw new ConfigurationException("BasketItem '{$key}' is required.");
            }
        }
        if (!isset($data['price']) || !is_numeric($data['price']) || (int) $data['price'] < 0) {
            throw new ConfigurationException("BasketItem 'price' is required and must be a non-negative integer (minor units).");
        }

        $out = [
            'id' => (string) $data['id'],
            'price' => (int) $data['price'],
            'name' => (string) $data['name'],
            'category1' => (string) $data['category1'],
            'itemType' => (string) $data['itemType'],
        ];
        if (isset($data['category2']) && $data['category2'] !== '') {
            $out['category2'] = (string) $data['category2'];
        }
        $this->data = $out;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
