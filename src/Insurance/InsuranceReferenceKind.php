<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Lunixi reference code lists read with InsuranceClient::listReferenceValues().
 * DISTRICT needs the province code, DEVICE_MODEL and VEHICLE_MODEL the brand
 * code, BANK_BRANCH the bank code and PET_BREED `CAT` or `DOG` as `parentCode`;
 * COUNTRY, CITY and BANK take none. National standards are the codes as they
 * are (ISO 3166-1 alpha-2 country, licence-plate province code `01`–`81`); the
 * others are Lunixi codes.
 */
final class InsuranceReferenceKind
{
    public const COUNTRY = 'COUNTRY';
    public const CITY = 'CITY';
    public const DISTRICT = 'DISTRICT';
    public const DEVICE_BRAND = 'DEVICE_BRAND';
    public const DEVICE_MODEL = 'DEVICE_MODEL';
    public const VEHICLE_BRAND = 'VEHICLE_BRAND';
    public const VEHICLE_MODEL = 'VEHICLE_MODEL';
    public const VEHICLE_TYPE = 'VEHICLE_TYPE';
    public const PRODUCT_CATEGORY = 'PRODUCT_CATEGORY';
    public const VEHICLE_USAGE = 'VEHICLE_USAGE';
    public const LIABILITY_LIMIT = 'LIABILITY_LIMIT';
    public const OCCUPATION = 'OCCUPATION';
    public const PET_BREED = 'PET_BREED';
    public const DWELLING_TYPE = 'DWELLING_TYPE';
    public const DWELLING_FLOOR = 'DWELLING_FLOOR';
    public const BANK = 'BANK';
    public const BANK_BRANCH = 'BANK_BRANCH';
    public const FINANCE_INSTITUTION = 'FINANCE_INSTITUTION';
    public const MEDICAL_PROCEDURE_COVER = 'MEDICAL_PROCEDURE_COVER';

    public const ALL = [
        self::COUNTRY,
        self::CITY,
        self::DISTRICT,
        self::DEVICE_BRAND,
        self::DEVICE_MODEL,
        self::VEHICLE_BRAND,
        self::VEHICLE_MODEL,
        self::VEHICLE_TYPE,
        self::PRODUCT_CATEGORY,
        self::VEHICLE_USAGE,
        self::LIABILITY_LIMIT,
        self::OCCUPATION,
        self::PET_BREED,
        self::DWELLING_TYPE,
        self::DWELLING_FLOOR,
        self::BANK,
        self::BANK_BRANCH,
        self::FINANCE_INSTITUTION,
        self::MEDICAL_PROCEDURE_COVER,
    ];

    private function __construct()
    {
    }
}
