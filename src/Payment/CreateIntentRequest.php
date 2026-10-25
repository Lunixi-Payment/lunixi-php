<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Builds the body for `POST /api/v1/payments/intents` (gateway CreatePaymentIntentDto).
 *
 * `amount` is the only amount field: the basket total in MINOR units (e.g.
 * 1000 = 10.00 TRY, an integer of at least 1), without any installment fee.
 * With basket items, their `price` values must add up to `amount`. The buyer
 * picks the installment on the hosted form and the system adds the installment
 * fee to the charged amount. Required: amount, currency, orderId. Everything
 * else is fluent/optional.
 *
 *   $req = (new CreateIntentRequest(1000, 'TRY', 'WC-1042'))
 *       ->withCallbackUrl('https://shop/checkout/callback')
 *       ->withBuyer($buyer);
 *
 * The same request also feeds the marketplace intent
 * (MarketplaceClient::createCheckoutIntent), `withPreauth()` included.
 */
final class CreateIntentRequest
{
    private int $amount;
    private string $currency;
    private string $orderId;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(int $amount, string $currency, string $orderId)
    {
        if ($amount < 1) {
            throw new ConfigurationException("CreateIntentRequest 'amount' must be a positive integer (minor units).");
        }
        $currency = strtoupper($currency);
        if (!Currency::isSupported($currency)) {
            throw new ConfigurationException("Unsupported currency '{$currency}'.");
        }
        if (trim($orderId) === '') {
            throw new ConfigurationException("CreateIntentRequest 'orderId' is required.");
        }

        $this->amount = $amount;
        $this->currency = $currency;
        $this->orderId = $orderId;
    }

    public function withLocale(string $locale): self
    {
        $this->optional['locale'] = $locale;
        return $this;
    }

    public function withCallbackUrl(string $url): self
    {
        $this->optional['callbackUrl'] = $url;
        return $this;
    }

    public function withDescription(string $description): self
    {
        $this->optional['description'] = $description;
        return $this;
    }

    public function withCustomerId(string $customerId): self
    {
        $this->optional['customerId'] = $customerId;
        return $this;
    }

    public function withCardUserKey(string $cardUserKey): self
    {
        $this->optional['cardUserKey'] = $cardUserKey;
        return $this;
    }

    public function withPaymentGroup(string $group): self
    {
        $this->optional['paymentGroup'] = $group;
        return $this;
    }

    public function withPaymentChannel(string $channel): self
    {
        $this->optional['paymentChannel'] = $channel;
        return $this;
    }

    public function withMethod(string $method): self
    {
        $this->optional['method'] = $method;
        return $this;
    }

    public function withPaymentMethod(string $paymentMethod): self
    {
        $this->optional['paymentMethod'] = $paymentMethod;
        return $this;
    }

    public function withWalletProvider(string $walletProvider): self
    {
        $this->optional['walletProvider'] = $walletProvider;
        return $this;
    }

    /**
     * Senetle Ödeme on the hosted form: your schedule of notes
     * (`[['amount' => …, 'dueDate' => 'YYYY-MM-DD'], …]`, minor units adding up
     * to `amount`, ascending dates, at most 24 rows). The form shows the plan
     * and the buyer does not choose a note count; without it the buyer picks
     * the number of notes within your provider connection limits. Only with no
     * `paymentMethod` or with PROMISSORY_NOTE.
     *
     * @param list<array{amount:int,dueDate:string}> $schedule
     */
    public function withPromissoryNoteSchedule(array $schedule): self
    {
        $this->optional['promissoryNote'] = ['schedule' => PromissoryNotePlan::requireSchedule($schedule)];
        return $this;
    }

    public function withForce3D(bool $force3D): self
    {
        $this->optional['force3D'] = $force3D;
        return $this;
    }

    /**
     * @param array<string,mixed> $settings CreateFormSettingsOverrideDto shape.
     */
    public function withSettings(array $settings): self
    {
        $this->optional['settings'] = $settings;
        return $this;
    }

    /**
     * Pre-authorization. `true` holds the amount on the card and the payment
     * becomes `AUTHORIZED`; take it later with PaymentClient::capture() or
     * release it with PaymentClient::void(). `false` (or not calling this)
     * captures immediately. Sent as the typed `preauth` field.
     */
    public function withPreauth(bool $preauth): self
    {
        $this->optional['preauth'] = $preauth;
        return $this;
    }

    /**
     * @deprecated since 0.16.0, use withPreauth(). `withAutoCapture(false)` is
     *   `withPreauth(true)`. Writes the typed `preauth` field; nothing is written
     *   to `metadata` any more.
     */
    public function withAutoCapture(bool $autoCapture): self
    {
        return $this->withPreauth(!$autoCapture);
    }

    public function withBuyer(Buyer $buyer): self
    {
        $this->optional['buyer'] = $buyer->toArray();
        return $this;
    }

    public function withBillingAddress(Address $address): self
    {
        $this->optional['billingAddress'] = $address->toArray();
        return $this;
    }

    public function withShippingAddress(Address $address): self
    {
        $this->optional['shippingAddress'] = $address->toArray();
        return $this;
    }

    /** @param BasketItem[] $items */
    public function withBasketItems(array $items): self
    {
        $this->optional['basketItems'] = array_map(
            static fn (BasketItem $item): array => $item->toArray(),
            array_values($items)
        );
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->optional['metadata'] = $metadata;
        return $this;
    }

    /** @return array<string,mixed> The request body for the gateway. */
    public function toArray(): array
    {
        if (isset($this->optional['promissoryNote'])) {
            $method = $this->optional['paymentMethod'] ?? null;
            if ($method !== null && $method !== PaymentMethod::PROMISSORY_NOTE) {
                throw new ConfigurationException(sprintf('A promissory-note schedule cannot be sent with paymentMethod %s.', (string) $method));
            }
            PromissoryNotePlan::assertMatchesAmount($this->optional['promissoryNote']['schedule'], $this->amount);
        }

        return array_merge([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'orderId' => $this->orderId,
        ], $this->optional);
    }
}
