<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use DateTimeInterface;
use DateTimeZone;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Builds the body of `POST /api/v1/payments/links`.
 *
 * Amounts are integers in the currency's minor unit (ISO 4217 exponent):
 * 150000 is 1,500.00 TRY. `usage`, `amountMode` and `currency` are fixed at
 * creation. This builder checks types and closed value sets; the business rules
 * (title length, amount ranges, which fields a mode allows) are enforced by the
 * server, which answers 422 `payment_link.validation.invalid` with
 * `details.errors[{field, reason}]`.
 *
 *   $request = CreatePaymentLinkRequest::fixed(PaymentLinkUsage::SINGLE_USE, 150000, 'TRY', 'Consulting fee')
 *       ->withExpiresAt(new DateTimeImmutable('+14 days'))
 *       ->withReference('INV-2026-0042')
 *       ->withRecipient(['email' => 'buyer@example.com']);
 */
final class CreatePaymentLinkRequest
{
    public const ENVIRONMENT_TEST = 'TEST';
    public const ENVIRONMENT_LIVE = 'LIVE';

    /** Values of each `withBuyerFields()` entry. */
    public const BUYER_FIELD_HIDDEN = 'HIDDEN';
    public const BUYER_FIELD_OPTIONAL = 'OPTIONAL';
    public const BUYER_FIELD_REQUIRED = 'REQUIRED';

    /** Keys of `withBuyerFields()`. */
    public const BUYER_FIELDS = ['name', 'email', 'phone', 'address', 'identityNumber'];

    /** Languages of the payment page (`withLocale()`). */
    public const LOCALE_TR = 'tr';
    public const LOCALE_EN = 'en';
    public const LOCALES = [self::LOCALE_TR, self::LOCALE_EN];

    public const BUTTON_PAY = 'PAY';
    public const BUTTON_DONATE = 'DONATE';
    public const BUTTON_BUY = 'BUY';
    public const BUTTON_BOOK = 'BOOK';

    /**
     * Every definition field of a payment link, in the gateway's REST
     * (camelCase) spelling. A create body may carry these plus `environment`;
     * an update body these plus `expectedRowVersion`. The gateway rejects any
     * other key with 400.
     */
    public const FIELDS = [
        'usage', 'amountMode', 'currency',
        'amountMinor', 'minAmountMinor', 'maxAmountMinor', 'suggestedAmountsMinor',
        'quantityEnabled', 'minQuantity', 'maxQuantity', 'items', 'capacity',
        'title', 'description', 'imageAssetId', 'successMessage', 'successRedirectUrl', 'taxNote', 'locale',
        'startsAt', 'expiresAt', 'eventAt', 'buttonLabelKey', 'requireTermsAcceptance',
        'buyerFields', 'customFields', 'recipient', 'prefill', 'verification', 'reminders', 'checkout',
        'reference', 'tags', 'branchCode', 'salesChannel', 'campaign', 'agentCode',
        'merchantNotifyEmails', 'metadata',
    ];

    /** Fixed at creation; an update naming one answers 422 `payment_link.link.immutable_field`. */
    public const IMMUTABLE_FIELDS = ['usage', 'amountMode', 'currency'];

    /** Fields that are JSON objects (an empty one is sent as `{}`, never `[]`). */
    public const OBJECT_FIELDS = ['buyerFields', 'recipient', 'prefill', 'verification', 'reminders', 'checkout', 'metadata'];

    /** Fields that carry an ISO-8601 time. */
    public const TIME_FIELDS = ['startsAt', 'expiresAt', 'eventAt'];

    /**
     * Largest integer a JSON number keeps exactly on the gateway (2^53 - 1). A
     * larger amount would be rounded when the gateway parses the body, and the
     * request would fail its Digest check.
     */
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * @internal Shared with PaymentLinkClient.
     * Shape of a media asset id (the gateway checks it as a uuid; ownership and state are the server's).
     */
    public const ASSET_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var array<string,mixed> */
    private array $payload;

    public function __construct(string $usage, string $amountMode, string $currency, string $title)
    {
        self::assertOneOf($usage, PaymentLinkUsage::ALL, 'usage');
        self::assertOneOf($amountMode, PaymentLinkAmountMode::ALL, 'amountMode');
        $currency = strtoupper(trim($currency));
        if (!Currency::isSupported($currency)) {
            throw new ConfigurationException("Unsupported currency '{$currency}'.");
        }
        if (trim($title) === '') {
            throw new ConfigurationException("CreatePaymentLinkRequest 'title' is required.");
        }

        $this->payload = [
            'usage' => $usage,
            'amountMode' => $amountMode,
            'currency' => $currency,
            'title' => $title,
        ];
    }

    /** A link for a set amount. */
    public static function fixed(string $usage, int $amountMinor, string $currency, string $title): self
    {
        return (new self($usage, PaymentLinkAmountMode::FIXED, $currency, $title))->withAmountMinor($amountMinor);
    }

    /**
     * A link where the buyer enters the amount (donations, deposits).
     * `minAmountMinor` is required; `maxAmountMinor` and up to six ascending
     * suggested amounts are optional.
     *
     * @param int[] $suggestedAmountsMinor
     */
    public static function open(
        string $usage,
        string $currency,
        string $title,
        int $minAmountMinor,
        ?int $maxAmountMinor = null,
        array $suggestedAmountsMinor = []
    ): self {
        $request = (new self($usage, PaymentLinkAmountMode::OPEN, $currency, $title))->withMinAmountMinor($minAmountMinor);
        if ($maxAmountMinor !== null) {
            $request->withMaxAmountMinor($maxAmountMinor);
        }
        if ($suggestedAmountsMinor !== []) {
            $request->withSuggestedAmountsMinor($suggestedAmountsMinor);
        }
        return $request;
    }

    /**
     * A link where the buyer picks quantities of the given lines (1–50).
     *
     * @param PaymentLinkItem[] $items
     */
    public static function itemized(string $usage, string $currency, string $title, array $items): self
    {
        return (new self($usage, PaymentLinkAmountMode::ITEMIZED, $currency, $title))->withItems($items);
    }

    /**
     * Optional. The API key already decides the environment; when set, it must
     * equal the key's environment or the server answers 403
     * `payment_link.environment.mismatch`.
     */
    public function withEnvironment(string $environment): self
    {
        self::assertOneOf($environment, [self::ENVIRONMENT_TEST, self::ENVIRONMENT_LIVE], 'environment');
        $this->payload['environment'] = $environment;
        return $this;
    }

    public function withAmountMinor(int $amountMinor): self
    {
        $this->payload['amountMinor'] = self::positiveMinor($amountMinor, 'amountMinor');
        return $this;
    }

    public function withMinAmountMinor(int $minAmountMinor): self
    {
        $this->payload['minAmountMinor'] = self::positiveMinor($minAmountMinor, 'minAmountMinor');
        return $this;
    }

    public function withMaxAmountMinor(int $maxAmountMinor): self
    {
        $this->payload['maxAmountMinor'] = self::positiveMinor($maxAmountMinor, 'maxAmountMinor');
        return $this;
    }

    /** @param int[] $amountsMinor Up to six, ascending, within [min, max]. */
    public function withSuggestedAmountsMinor(array $amountsMinor): self
    {
        $values = [];
        foreach ($amountsMinor as $amount) {
            if (!is_int($amount)) {
                throw new ConfigurationException("'suggestedAmountsMinor' must be integers in minor units.");
            }
            $values[] = self::positiveMinor($amount, 'suggestedAmountsMinor');
        }
        $this->payload['suggestedAmountsMinor'] = $values;
        return $this;
    }

    /** Lets the buyer choose a quantity, 1..999 (FIXED + MULTI_USE only). */
    public function withQuantity(int $minQuantity, int $maxQuantity): self
    {
        $this->payload['quantityEnabled'] = true;
        $this->payload['minQuantity'] = $minQuantity;
        $this->payload['maxQuantity'] = $maxQuantity;
        return $this;
    }

    /** @param PaymentLinkItem[] $items */
    public function withItems(array $items): self
    {
        if ($items === []) {
            throw new ConfigurationException('An ITEMIZED payment link needs at least one item.');
        }
        $this->payload['items'] = [];
        foreach ($items as $item) {
            $this->addItem($item);
        }
        return $this;
    }

    public function addItem(PaymentLinkItem $item): self
    {
        $items = isset($this->payload['items']) && is_array($this->payload['items']) ? $this->payload['items'] : [];
        $items[] = $item->toArray();
        $this->payload['items'] = $items;
        return $this;
    }

    /** Total units the link can sell across all payments (MULTI_USE only); null = unlimited. */
    public function withCapacity(?int $capacity): self
    {
        $this->payload['capacity'] = $capacity;
        return $this;
    }

    /** Plain text, shown as text (no HTML or Markdown). */
    public function withDescription(string $description): self
    {
        $this->payload['description'] = $description;
        return $this;
    }

    /** Id (uuid) of a published payment-link image asset of your organisation; null = none. */
    public function withImageAssetId(?string $assetId): self
    {
        $this->payload['imageAssetId'] = self::assetIdOrNull($assetId, 'imageAssetId');
        return $this;
    }

    public function withSuccessMessage(string $message): self
    {
        $this->payload['successMessage'] = $message;
        return $this;
    }

    /** `https://` only. */
    public function withSuccessRedirectUrl(?string $url): self
    {
        $this->payload['successRedirectUrl'] = $url;
        return $this;
    }

    /** e.g. "KDV dahil" (VAT included), ≤ 80 characters. */
    public function withTaxNote(string $taxNote): self
    {
        $this->payload['taxNote'] = $taxNote;
        return $this;
    }

    /** Language of the payment page: LOCALE_TR or LOCALE_EN. */
    public function withLocale(string $locale): self
    {
        self::assertOneOf($locale, self::LOCALES, 'locale');
        $this->payload['locale'] = $locale;
        return $this;
    }

    /** @param DateTimeInterface|string|null $startsAt */
    public function withStartsAt($startsAt): self
    {
        $this->payload['startsAt'] = self::isoTime($startsAt, 'startsAt');
        return $this;
    }

    /** @param DateTimeInterface|string|null $expiresAt */
    public function withExpiresAt($expiresAt): self
    {
        $this->payload['expiresAt'] = self::isoTime($expiresAt, 'expiresAt');
        return $this;
    }

    /**
     * Display only (e.g. the date of the event a ticket is for).
     *
     * @param DateTimeInterface|string|null $eventAt
     */
    public function withEventAt($eventAt): self
    {
        $this->payload['eventAt'] = self::isoTime($eventAt, 'eventAt');
        return $this;
    }

    /** One of the BUTTON_* constants; null = your organisation's default. */
    public function withButtonLabelKey(?string $labelKey): self
    {
        if ($labelKey !== null) {
            self::assertOneOf($labelKey, [self::BUTTON_PAY, self::BUTTON_DONATE, self::BUTTON_BUY, self::BUTTON_BOOK], 'buttonLabelKey');
        }
        $this->payload['buttonLabelKey'] = $labelKey;
        return $this;
    }

    /** The buyer must accept your distance-sales/terms link before paying. */
    public function withTermsAcceptanceRequired(bool $required = true): self
    {
        $this->payload['requireTermsAcceptance'] = $required;
        return $this;
    }

    /**
     * Which standard buyer fields the payment page asks for.
     *
     * @param array<string,string> $fields Keys from BUYER_FIELDS; values BUYER_FIELD_*.
     */
    public function withBuyerFields(array $fields): self
    {
        $allowed = [self::BUYER_FIELD_HIDDEN, self::BUYER_FIELD_OPTIONAL, self::BUYER_FIELD_REQUIRED];
        foreach ($fields as $name => $requirement) {
            if (!in_array($name, self::BUYER_FIELDS, true)) {
                throw new ConfigurationException(
                    "Unknown buyer field '{$name}'. Allowed: " . implode(', ', self::BUYER_FIELDS) . '.'
                );
            }
            self::assertOneOf((string) $requirement, $allowed, "buyerFields.{$name}");
        }
        $this->payload['buyerFields'] = $fields;
        return $this;
    }

    /** @param PaymentLinkCustomField[] $fields */
    public function withCustomFields(array $fields): self
    {
        $this->payload['customFields'] = [];
        foreach ($fields as $field) {
            $this->addCustomField($field);
        }
        return $this;
    }

    public function addCustomField(PaymentLinkCustomField $field): self
    {
        $fields = isset($this->payload['customFields']) && is_array($this->payload['customFields'])
            ? $this->payload['customFields']
            : [];
        $fields[] = $field->toArray();
        $this->payload['customFields'] = $fields;
        return $this;
    }

    /**
     * Who the link is for. Stored encrypted; the payment page shows it masked.
     *
     * @param array{name?:string, email?:string, phone?:string} $recipient phone in E.164.
     */
    public function withRecipient(array $recipient): self
    {
        $this->payload['recipient'] = self::stringObject($recipient, ['name', 'email', 'phone'], 'recipient');
        return $this;
    }

    /**
     * Buyer details filled in on the server side; they are never sent to the
     * browser.
     *
     * @param array<string,string> $prefill name, surname, email, phone, identityNumber,
     *   address, city, country (ISO 3166-1 alpha-2), zipCode.
     */
    public function withPrefill(array $prefill): self
    {
        $this->payload['prefill'] = self::stringObject(
            $prefill,
            ['name', 'surname', 'email', 'phone', 'identityNumber', 'address', 'city', 'country', 'zipCode'],
            'prefill'
        );
        return $this;
    }

    /**
     * Requires the buyer to confirm a one-time code sent to `recipient.email`
     * before paying. Only EMAIL is available.
     */
    public function withVerification(bool $required = true, string $channel = 'EMAIL'): self
    {
        self::assertOneOf($channel, [PaymentLinkClient::CHANNEL_EMAIL, PaymentLinkClient::CHANNEL_SMS], 'verification channel');
        $this->payload['verification'] = ['required' => $required, 'channel' => $channel];
        return $this;
    }

    /** Reminder messages (SINGLE_USE with a recipient): every 24–168 hours, at most 1–3 times. */
    public function withReminders(int $intervalHours, int $maxCount): self
    {
        $this->payload['reminders'] = ['enabled' => true, 'intervalHours' => $intervalHours, 'maxCount' => $maxCount];
        return $this;
    }

    /**
     * Narrows the checkout: `requireThreeDs` can only tighten; `paymentMethods`
     * is a subset of your form settings (empty = your form settings).
     *
     * @param string[] $paymentMethods
     */
    public function withCheckout(bool $requireThreeDs = false, array $paymentMethods = []): self
    {
        $this->payload['checkout'] = [
            'requireThreeDs' => $requireThreeDs,
            'paymentMethods' => self::stringList($paymentMethods, 'paymentMethods'),
        ];
        return $this;
    }

    /** Your reference (invoice number, order id), ≤ 128 characters. */
    public function withReference(string $reference): self
    {
        $this->payload['reference'] = $reference;
        return $this;
    }

    /** @param string[] $tags Up to 10, `[a-z0-9_-]{1,32}`. */
    public function withTags(array $tags): self
    {
        $this->payload['tags'] = self::stringList($tags, 'tags');
        return $this;
    }

    public function withBranchCode(string $branchCode): self
    {
        $this->payload['branchCode'] = $branchCode;
        return $this;
    }

    public function withSalesChannel(string $salesChannel): self
    {
        $this->payload['salesChannel'] = $salesChannel;
        return $this;
    }

    public function withCampaign(string $campaign): self
    {
        $this->payload['campaign'] = $campaign;
        return $this;
    }

    public function withAgentCode(string $agentCode): self
    {
        $this->payload['agentCode'] = $agentCode;
        return $this;
    }

    /** @param string[] $emails Up to 5 addresses told when the link is paid. */
    public function withMerchantNotifyEmails(array $emails): self
    {
        $this->payload['merchantNotifyEmails'] = self::stringList($emails, 'merchantNotifyEmails');
        return $this;
    }

    /**
     * Up to 20 string pairs, returned to you in the link's webhooks.
     *
     * @param array<string,string> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        foreach ($metadata as $value) {
            if (!is_string($value)) {
                throw new ConfigurationException("'metadata' values must be strings.");
            }
        }
        $this->payload['metadata'] = $metadata;
        return $this;
    }

    /** @return array<string,mixed> The request body for the gateway. */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @param DateTimeInterface|string|null $value
     */
    public static function isoTime($value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromFormat('U.u', $value->format('U.u'), new DateTimeZone('UTC'));
            if ($utc === false) {
                throw new ConfigurationException("'{$field}' is not a valid time.");
            }
            return $utc->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }
        throw new ConfigurationException("'{$field}' must be a DateTimeInterface, an ISO-8601 string or null.");
    }

    /** @internal Shared with PaymentLinkItem. */
    public static function assetIdOrNull(?string $assetId, string $field): ?string
    {
        if ($assetId !== null && preg_match(self::ASSET_ID_PATTERN, $assetId) !== 1) {
            throw new ConfigurationException("'{$field}' must be an asset id (uuid) or null; got '{$assetId}'.");
        }
        return $assetId;
    }

    /** @param string[] $allowed */
    private static function assertOneOf(string $value, array $allowed, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new ConfigurationException("'{$field}' must be one of " . implode(', ', $allowed) . "; got '{$value}'.");
        }
    }

    /** @internal Shared with PaymentLinkItem. */
    public static function positiveMinor(int $value, string $field): int
    {
        if ($value < 1 || $value > self::MAX_SAFE_INTEGER) {
            throw new ConfigurationException("'{$field}' must be a positive integer in minor units (150000 = 1,500.00 TRY).");
        }
        return $value;
    }

    /**
     * @param array<mixed> $values
     * @return string[]
     */
    private static function stringList(array $values, string $field): array
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new ConfigurationException("'{$field}' must be a list of strings.");
            }
        }
        return array_values($values);
    }

    /**
     * @param array<mixed> $value
     * @param string[] $keys
     * @return array<string,string>
     */
    private static function stringObject(array $value, array $keys, string $field): array
    {
        foreach ($value as $key => $entry) {
            if (!in_array($key, $keys, true)) {
                throw new ConfigurationException("Unknown {$field} field '{$key}'. Allowed: " . implode(', ', $keys) . '.');
            }
            if (!is_string($entry)) {
                throw new ConfigurationException("{$field}.{$key} must be a string.");
            }
        }
        return $value;
    }
}
