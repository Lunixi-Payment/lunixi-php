<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Item kind for insurance (`BasketItem` `insuranceCategory`). Lunixi resolves the
 * insurance types offered for the item from this value; `insuranceTypes` narrows
 * the list. Accepted on `createIntent` (Checkout Form) only.
 */
final class InsuranceItemCategory
{
    /** Bus, flight or train ticket. */
    public const TICKET = 'TICKET';
    /** Accommodation booking. */
    public const HOTEL = 'HOTEL';
    /** Event ticket. */
    public const EVENT = 'EVENT';
    /** Mobile phone. */
    public const DEVICE = 'DEVICE';
    /** Electronics and home appliances. */
    public const ELECTRONICS = 'ELECTRONICS';
    /** Vehicle. */
    public const VEHICLE = 'VEHICLE';
    /** Travel or tour package. */
    public const TRAVEL = 'TRAVEL';
    /** Home. */
    public const HOME = 'HOME';
    /** Health service. */
    public const HEALTH = 'HEALTH';
    /** Pet. */
    public const PET = 'PET';
    /** Rented scooter and other micromobility. */
    public const MICROMOBILITY = 'MICROMOBILITY';
    /** Musical instrument. */
    public const MUSICAL_INSTRUMENT = 'MUSICAL_INSTRUMENT';
    /** Shipment, cargo. */
    public const SHIPMENT = 'SHIPMENT';
    /** Education, studying abroad. */
    public const EDUCATION = 'EDUCATION';

    public const ALL = [
        self::TICKET,
        self::HOTEL,
        self::EVENT,
        self::DEVICE,
        self::ELECTRONICS,
        self::VEHICLE,
        self::TRAVEL,
        self::HOME,
        self::HEALTH,
        self::PET,
        self::MICROMOBILITY,
        self::MUSICAL_INSTRUMENT,
        self::SHIPMENT,
        self::EDUCATION,
    ];

    private function __construct()
    {
    }
}
