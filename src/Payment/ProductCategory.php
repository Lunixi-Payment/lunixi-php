<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Lunixi product category (`productCategory` of a `BasketItem` or a `PaymentLinkItem`).
 * Used by payment methods with legal installment limits (e.g. shopping loan) and other
 * platform features. Optional on every endpoint that takes basket items; the gateway
 * answers an unknown value with 400. The list only grows by appending, so the SDK does
 * not check a value against `ALL` before sending.
 */
final class ProductCategory
{
    public const MOBILE_PHONE = 'MOBILE_PHONE';
    public const TABLET = 'TABLET';
    public const COMPUTER = 'COMPUTER';
    public const TV_AUDIO = 'TV_AUDIO';
    public const WHITE_GOODS = 'WHITE_GOODS';
    public const SMALL_APPLIANCE = 'SMALL_APPLIANCE';
    public const CAMERA = 'CAMERA';
    public const GAME_CONSOLE = 'GAME_CONSOLE';
    public const WEARABLE = 'WEARABLE';
    public const ELECTRONICS_ACCESSORY = 'ELECTRONICS_ACCESSORY';
    public const ELECTRONICS_OTHER = 'ELECTRONICS_OTHER';
    public const GOLD = 'GOLD';
    public const JEWELRY = 'JEWELRY';
    public const WATCH = 'WATCH';
    public const APPAREL = 'APPAREL';
    public const SHOES_BAGS = 'SHOES_BAGS';
    public const COSMETICS = 'COSMETICS';
    public const FURNITURE = 'FURNITURE';
    public const HOME_LIVING = 'HOME_LIVING';
    public const GROCERY = 'GROCERY';
    public const BOOKS_MEDIA = 'BOOKS_MEDIA';
    public const TOYS = 'TOYS';
    public const SPORTS_OUTDOOR = 'SPORTS_OUTDOOR';
    public const PET_SUPPLIES = 'PET_SUPPLIES';
    public const AUTO_PARTS = 'AUTO_PARTS';
    public const MUSICAL_INSTRUMENT = 'MUSICAL_INSTRUMENT';
    public const MICROMOBILITY = 'MICROMOBILITY';
    public const VEHICLE = 'VEHICLE';
    public const TRANSPORT_TICKET = 'TRANSPORT_TICKET';
    public const EVENT_TICKET = 'EVENT_TICKET';
    public const ACCOMMODATION = 'ACCOMMODATION';
    public const TRAVEL_PACKAGE = 'TRAVEL_PACKAGE';
    public const HEALTH_SERVICE = 'HEALTH_SERVICE';
    public const EDUCATION = 'EDUCATION';
    public const SHIPPING_SERVICE = 'SHIPPING_SERVICE';
    public const DIGITAL_GOODS = 'DIGITAL_GOODS';
    public const GIFT_CARD = 'GIFT_CARD';
    public const SUBSCRIPTION = 'SUBSCRIPTION';
    public const SERVICE_OTHER = 'SERVICE_OTHER';
    public const OTHER = 'OTHER';

    public const ALL = [
        self::MOBILE_PHONE,
        self::TABLET,
        self::COMPUTER,
        self::TV_AUDIO,
        self::WHITE_GOODS,
        self::SMALL_APPLIANCE,
        self::CAMERA,
        self::GAME_CONSOLE,
        self::WEARABLE,
        self::ELECTRONICS_ACCESSORY,
        self::ELECTRONICS_OTHER,
        self::GOLD,
        self::JEWELRY,
        self::WATCH,
        self::APPAREL,
        self::SHOES_BAGS,
        self::COSMETICS,
        self::FURNITURE,
        self::HOME_LIVING,
        self::GROCERY,
        self::BOOKS_MEDIA,
        self::TOYS,
        self::SPORTS_OUTDOOR,
        self::PET_SUPPLIES,
        self::AUTO_PARTS,
        self::MUSICAL_INSTRUMENT,
        self::MICROMOBILITY,
        self::VEHICLE,
        self::TRANSPORT_TICKET,
        self::EVENT_TICKET,
        self::ACCOMMODATION,
        self::TRAVEL_PACKAGE,
        self::HEALTH_SERVICE,
        self::EDUCATION,
        self::SHIPPING_SERVICE,
        self::DIGITAL_GOODS,
        self::GIFT_CARD,
        self::SUBSCRIPTION,
        self::SERVICE_OTHER,
        self::OTHER,
    ];

    private function __construct()
    {
    }
}
