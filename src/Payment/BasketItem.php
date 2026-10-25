<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * A basket line item (matches the gateway BasketItemDto / InsurableBasketItemDto).
 * `price` is the line total in minor units (e.g. 1000 = 10.00).
 * Required: id, price, name, category1, itemType (PHYSICAL or VIRTUAL).
 *
 * Optional insurance fields (insuranceCategory, insuranceTypes, insuranceEligible,
 * insuranceValueAmount, insuranceContext, insuranceData) are accepted by `createIntent`
 * (Checkout Form) and by the insurance calls' `context.items`; `directMethodPayment`
 * (direct/payment) answers them with 400. They do not reach the payment provider. When
 * any item carries one, the `id`s in the basket must be unique.
 *
 * `insuranceData` is what the insurance quote needs that only you know (the insured, PNR,
 * IMEI, plate …): the root fields of the type's quote body (`policyholder`, `insured`, the
 * type object such as `tickets` or `device`). A list type object (`tickets`) is ONE object
 * for this item, without `basketItemId`. Partial data is allowed; the buyer is asked only
 * for what is missing. It carries personal data: Lunixi stores it encrypted for the quote
 * only and erases it after the payment intent expires.
 */
final class BasketItem
{
    public const TYPE_PHYSICAL = 'PHYSICAL';
    public const TYPE_VIRTUAL = 'VIRTUAL';

    public const ITEM_TYPES = [self::TYPE_PHYSICAL, self::TYPE_VIRTUAL];

    /** Lunixi insurance type code, e.g. TICKET_CANCELLATION. */
    public const INSURANCE_TYPE_PATTERN = '/^[A-Z][A-Z0-9_]{2,63}$/';
    public const MAX_INSURANCE_TYPES = 10;

    /** Person roots of `insuranceData`. */
    public const INSURANCE_DATA_PERSON_ROOTS = ['policyholder', 'insured'];

    /** Type object roots of `insuranceData` (the type objects of the quote body). */
    public const INSURANCE_DATA_TYPE_ROOTS = [
        'tickets', 'stay', 'device', 'product', 'vehicle', 'trip', 'rental', 'instrument',
        'event', 'accident', 'procedure', 'pet', 'residence', 'abroadStay', 'property', 'health',
    ];

    /** Most insured persons in `insuranceData.insured`. */
    public const INSURANCE_DATA_INSURED_MAX = 10;

    /** Most bytes of `insuranceData` as JSON (UTF-8). */
    public const INSURANCE_DATA_MAX_BYTES = 16384;

    /** @var array<string,mixed> */
    private array $data;

    /**
     * @param array<string,mixed> $data Keys: id, price(int minor), name, category1, itemType, category2?,
     *   insuranceCategory? (InsuranceItemCategory), insuranceTypes? (string[]), insuranceEligible? (bool),
     *   insuranceValueAmount? (int minor, one unit), insuranceContext? (array<string,mixed>: the
     *   category's prefill keys, e.g. transportMode, departureAt; no personal data), insuranceData?
     *   (array<string,mixed>: policyholder?, insured? (list), one type object, e.g. tickets; at most
     *   16 KB as JSON). A null value, or an empty insuranceContext or insuranceData, counts as not sent.
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
        if (!in_array((string) $data['itemType'], self::ITEM_TYPES, true)) {
            throw new ConfigurationException("BasketItem 'itemType' must be PHYSICAL or VIRTUAL.");
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
        $this->data = $out + self::insuranceFields($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Checks the insurance fields the way the gateway does and returns the ones sent.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws ConfigurationException
     */
    private static function insuranceFields(array $data): array
    {
        $out = [];

        if (isset($data['insuranceCategory'])) {
            if (!in_array($data['insuranceCategory'], InsuranceItemCategory::ALL, true)) {
                throw new ConfigurationException(
                    "BasketItem 'insuranceCategory' must be one of " . implode(', ', InsuranceItemCategory::ALL) . '.'
                );
            }
            $out['insuranceCategory'] = $data['insuranceCategory'];
        }

        if (isset($data['insuranceTypes'])) {
            $types = $data['insuranceTypes'];
            if (!is_array($types) || array_values($types) !== $types || count($types) > self::MAX_INSURANCE_TYPES) {
                throw new ConfigurationException(
                    "BasketItem 'insuranceTypes' must be a list of at most " . self::MAX_INSURANCE_TYPES . ' type codes.'
                );
            }
            foreach ($types as $code) {
                if (!is_string($code) || preg_match(self::INSURANCE_TYPE_PATTERN, $code) !== 1) {
                    throw new ConfigurationException(
                        "BasketItem 'insuranceTypes' must contain Lunixi insurance type codes (e.g. TICKET_CANCELLATION)."
                    );
                }
            }
            if (count(array_unique($types)) !== count($types)) {
                throw new ConfigurationException("BasketItem 'insuranceTypes' must not repeat a type code.");
            }
            $out['insuranceTypes'] = $types;
        }

        if (isset($data['insuranceEligible'])) {
            if (!is_bool($data['insuranceEligible'])) {
                throw new ConfigurationException("BasketItem 'insuranceEligible' must be a boolean.");
            }
            $out['insuranceEligible'] = $data['insuranceEligible'];
        }

        if (isset($data['insuranceValueAmount'])) {
            if (!is_int($data['insuranceValueAmount']) || $data['insuranceValueAmount'] < 1) {
                throw new ConfigurationException(
                    "BasketItem 'insuranceValueAmount' must be a positive integer (minor units, one unit)."
                );
            }
            $out['insuranceValueAmount'] = $data['insuranceValueAmount'];
        }

        if (isset($data['insuranceContext']) && $data['insuranceContext'] !== []) {
            $context = $data['insuranceContext'];
            if (!is_array($context) || array_values($context) === $context) {
                throw new ConfigurationException("BasketItem 'insuranceContext' must be an array keyed by field name.");
            }
            $out['insuranceContext'] = $context;
        }

        if (isset($data['insuranceData']) && $data['insuranceData'] !== []) {
            $insuranceData = self::insuranceData($data['insuranceData']);
            if ($insuranceData !== []) {
                $out['insuranceData'] = $insuranceData;
            }
        }

        $needsCategory = isset($out['insuranceTypes']) || isset($out['insuranceValueAmount'])
            || isset($out['insuranceContext']) || isset($out['insuranceData']);
        if ($needsCategory && !isset($out['insuranceCategory'])) {
            throw new ConfigurationException(
                "BasketItem 'insuranceCategory' is required when insuranceTypes, insuranceValueAmount, insuranceContext or insuranceData is sent."
            );
        }

        return $out;
    }

    /**
     * Checks the shape of `insuranceData` the way the gateway does (known roots, `insured` a
     * list of 1–10 objects, the other roots one object, no `basketItemId` in a type object, no
     * null value inside, at most 16 KB as JSON). A null root counts as not sent; with only null
     * roots the field is left out. Field formats are checked by the gateway; its 400 names the
     * field path, never the value. Messages here carry no value either.
     *
     * @param mixed $value
     * @return array<string,mixed>
     * @throws ConfigurationException
     */
    private static function insuranceData($value): array
    {
        if (!is_array($value) || array_values($value) === $value) {
            throw new ConfigurationException(
                "BasketItem 'insuranceData' must be an array keyed by root field (policyholder, insured, the type object)."
            );
        }
        $value = array_filter($value, static fn ($entry): bool => $entry !== null);
        $roots = array_merge(self::INSURANCE_DATA_PERSON_ROOTS, self::INSURANCE_DATA_TYPE_ROOTS);
        foreach ($value as $root => $entry) {
            $path = "insuranceData.{$root}";
            if (!in_array($root, $roots, true)) {
                throw new ConfigurationException("BasketItem '{$path}' is not a known root; use " . implode(', ', $roots) . '.');
            }
            if ($root === 'insured') {
                if (!is_array($entry) || $entry === [] || array_values($entry) !== $entry
                    || count($entry) > self::INSURANCE_DATA_INSURED_MAX || !self::allKeyed($entry)) {
                    throw new ConfigurationException(
                        "BasketItem '{$path}' must be a list of 1-" . self::INSURANCE_DATA_INSURED_MAX . ' objects.'
                    );
                }
            } elseif (!is_array($entry) || $entry === [] || array_values($entry) === $entry) {
                throw new ConfigurationException(
                    in_array($root, self::INSURANCE_DATA_TYPE_ROOTS, true)
                        ? "BasketItem '{$path}' must be one object keyed by field name (a list type object is sent as one object for the item)."
                        : "BasketItem '{$path}' must be an object keyed by field name."
                );
            } elseif (in_array($root, self::INSURANCE_DATA_TYPE_ROOTS, true) && array_key_exists('basketItemId', $entry)) {
                throw new ConfigurationException("BasketItem '{$path}.basketItemId' must not be sent; the item's id is used.");
            }
            self::assertNoNull($entry, $path);
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        if ($json === false || strlen($json) > self::INSURANCE_DATA_MAX_BYTES) {
            throw new ConfigurationException(
                "BasketItem 'insuranceData' must be at most " . self::INSURANCE_DATA_MAX_BYTES . ' bytes as JSON.'
            );
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @throws ConfigurationException
     */
    private static function assertNoNull($value, string $path): void
    {
        if (!is_array($value)) {
            return;
        }
        $isList = array_values($value) === $value;
        foreach ($value as $key => $entry) {
            $child = $isList ? "{$path}[{$key}]" : "{$path}.{$key}";
            if ($entry === null) {
                throw new ConfigurationException("BasketItem '{$child}' must not be null; leave the field out instead.");
            }
            self::assertNoNull($entry, $child);
        }
    }

    /** @param array<int,mixed> $entries */
    private static function allKeyed(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!is_array($entry) || $entry === [] || array_values($entry) === $entry) {
                return false;
            }
        }

        return true;
    }
}
