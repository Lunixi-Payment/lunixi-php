<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for POST /api/v1/payments/direct/payment (DirectMethodPaymentDto): a
 * payment with any payment method, started from the merchant server
 * (PaymentClient::directMethodPayment()).
 *
 * Choose the method with exactly one of:
 *   - withMethodFields($method, $providerCode, $fields) for a method other than
 *     card (Faturana Yansıt, wallets …). The fields are the provider's
 *     `inputFields` from PaymentClient::listPaymentMethods(); `$providerCode`
 *     may be null when the method's `providerSelection` is OPTIONAL (with
 *     `providerSelectionMode` PLATFORM the platform then picks the provider).
 *     Senetle Ödeme (PROMISSORY_NOTE) also needs the plan:
 *     withPromissoryNoteSchedule($schedule) or withPromissoryNoteCount($count).
 *   - withCard($card) for a card: the only server-side card charge. Without
 *     withForce3D() it is charged without 3D Secure (the bank may still ask
 *     for it: the response then carries a REDIRECT action); withForce3D(true)
 *     charges with 3D Secure and the response carries a REDIRECT action.
 *     A stored card is charged with `cardUserKey` + `cardToken` in CardDetails.
 *
 * Order fields: buyer, billingAddress, shippingAddress, basketItems,
 * paymentChannel, paymentGroup, description, customerId, metadata. `amount` is the
 * only amount field: the basket total in minor units (an integer of at least
 * 1), without any installment fee; basket item `price` values must add up to it.
 */
final class DirectMethodPaymentRequest
{
    /** @var array<string,mixed> */
    private array $data;

    private ?string $paymentMethod = null;

    /** @var array<string,mixed> */
    private array $block = [];

    /** @var array<string,mixed>|null Senetle Ödeme plan: ['schedule' => rows] or ['noteCount' => '3']. */
    private ?array $promissoryNotePlan = null;

    public function __construct(int $amount, string $currency, string $orderId)
    {
        if ($amount < 1) {
            throw new ConfigurationException("DirectMethodPaymentRequest 'amount' must be a positive integer (minor units).");
        }
        $currency = strtoupper($currency);
        if (!Currency::isSupported($currency)) {
            throw new ConfigurationException("Unsupported currency '{$currency}'.");
        }
        if (trim($orderId) === '') {
            throw new ConfigurationException("DirectMethodPaymentRequest 'orderId' is required.");
        }

        $this->data = [
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => $orderId,
        ];
    }

    /**
     * Pays with a method other than card. `$fields` are the provider's
     * `inputFields` values (e.g. `['gsmNumber' => '+905321234567']` for Faturana
     * Yansıt); `[]` when the provider asks for nothing or the value comes from
     * the buyer (`prefill`). `$providerCode` is required when the method lists
     * more than one provider for the buyer to pick (`providerSelection:
     * REQUIRED`); leave it null on a `providerSelectionMode` PLATFORM method to
     * let the platform pick.
     *
     * Values may also be an associative array (ADDRESS) or a list of
     * associative arrays (GROUP), e.g. guarantors.
     *
     * @param array<string,mixed> $fields
     */
    public function withMethodFields(string $paymentMethod, ?string $providerCode, array $fields = []): self
    {
        $code = PaymentMethod::normalize($paymentMethod);
        if ($code === PaymentMethod::CARD) {
            throw new ConfigurationException('Use withCard() to pay by card.');
        }
        $blockName = PaymentMethod::blockName($code);
        if (!in_array($blockName, PaymentMethod::METHOD_BLOCK_FIELDS, true)) {
            throw new ConfigurationException(sprintf(
                'paymentMethod %s cannot be paid through direct/payment yet; listPaymentMethods() lists the methods that can.',
                $code
            ));
        }
        $block = PaymentMethod::requireFields($fields, $blockName);
        if ($code === PaymentMethod::PROMISSORY_NOTE && array_key_exists('schedule', $block)) {
            throw new ConfigurationException('Pass the promissory-note schedule with withPromissoryNoteSchedule(), not in the fields.');
        }
        if ($code === PaymentMethod::PROMISSORY_NOTE && array_key_exists('noteCount', $block)) {
            $block['noteCount'] = PromissoryNotePlan::requireNoteCount($block['noteCount']);
        }

        $this->paymentMethod = $code;
        $this->block = $providerCode === null
            ? $block
            : ['providerCode' => PaymentMethod::requireProviderCode($providerCode)] + $block;

        return $this;
    }

    /**
     * Senetle Ödeme plan, one note per row:
     * `[['amount' => 100000, 'dueDate' => '2030-01-15'], …]`. Amounts are
     * positive integers in minor units adding up to `amount`; due dates are
     * `YYYY-MM-DD` (Istanbul calendar), after today, in ascending order, at
     * most 24 rows. Use with withMethodFields(PaymentMethod::PROMISSORY_NOTE, …);
     * replaces a note count.
     *
     * @param list<array{amount:int,dueDate:string}> $schedule
     */
    public function withPromissoryNoteSchedule(array $schedule): self
    {
        $this->promissoryNotePlan = ['schedule' => PromissoryNotePlan::requireSchedule($schedule)];
        return $this;
    }

    /**
     * Senetle Ödeme plan by number of notes (1–24, within your provider
     * connection limits; the `noteCount` options in `inputFields`). The amount
     * is split evenly, the remaining minor units go to the last note; due dates
     * follow your connection settings. Sent as a string. Replaces a schedule.
     *
     * @param int|string $count
     */
    public function withPromissoryNoteCount($count): self
    {
        $this->promissoryNotePlan = ['noteCount' => PromissoryNotePlan::requireNoteCount($count)];
        return $this;
    }

    /** Pays by card (CardDetails: new card fields, or `cardUserKey` + `cardToken` for a stored card). */
    public function withCard(CardDetails $card): self
    {
        $this->paymentMethod = PaymentMethod::CARD;
        $this->block = $card->toArray();

        return $this;
    }

    /**
     * Card only. `$quoteToken` binds the charge to a quote from
     * installmentOptions(); the system adds the quoted installment fee to `amount`.
     */
    public function withInstallment(int $installment, ?string $quoteToken = null): self
    {
        $this->data['installment'] = max(1, $installment);
        if ($quoteToken !== null && $quoteToken !== '') {
            $this->data['installmentQuoteToken'] = $quoteToken;
        }
        return $this;
    }

    /** Card only. `true` charges with 3D Secure: the response carries a REDIRECT action. */
    public function withForce3D(bool $force3D): self
    {
        $this->data['force3D'] = $force3D;
        return $this;
    }

    /**
     * Pre-authorization. `true` holds the amount and the payment becomes
     * `AUTHORIZED`; take it with PaymentClient::capture() or release it with
     * PaymentClient::void(). A method whose providers do not support it
     * (`preauth: false` in listPaymentMethods()) is refused with 422
     * `payment.preauth.unsupported_for_method`.
     */
    public function withPreauth(bool $preauth): self
    {
        $this->data['preauth'] = $preauth;
        return $this;
    }

    /** Where the buyer's browser returns after a REDIRECT action, with the signed result form. */
    public function withCallbackUrl(string $url): self
    {
        $this->data['callbackUrl'] = $url;
        return $this;
    }

    public function withLocale(string $locale): self
    {
        $this->data['locale'] = $locale;
        return $this;
    }

    public function withDescription(string $description): self
    {
        $this->data['description'] = $description;
        return $this;
    }

    public function withCustomerId(string $customerId): self
    {
        $this->data['customerId'] = $customerId;
        return $this;
    }

    public function withPaymentChannel(string $channel): self
    {
        $this->data['paymentChannel'] = $channel;
        return $this;
    }

    public function withPaymentGroup(string $group): self
    {
        $this->data['paymentGroup'] = $group;
        return $this;
    }

    public function withBuyer(Buyer $buyer): self
    {
        $this->data['buyer'] = $buyer->toArray();
        return $this;
    }

    public function withBillingAddress(Address $address): self
    {
        $this->data['billingAddress'] = $address->toArray();
        return $this;
    }

    public function withShippingAddress(Address $address): self
    {
        $this->data['shippingAddress'] = $address->toArray();
        return $this;
    }

    /** @param BasketItem[] $items */
    public function withBasketItems(array $items): self
    {
        $this->data['basketItems'] = array_map(
            static fn (BasketItem $item): array => $item->toArray(),
            array_values($items)
        );
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->data['metadata'] = $metadata;
        return $this;
    }

    /**
     * An empty method block is sent as a JSON object (`{}`), not as a list.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        if ($this->paymentMethod === null) {
            throw new ConfigurationException('Choose the payment method with withMethodFields() or withCard().');
        }
        if ($this->paymentMethod !== PaymentMethod::CARD) {
            foreach (PaymentMethod::CARD_ONLY_FIELDS as $field) {
                if (array_key_exists($field, $this->data)) {
                    throw new ConfigurationException(sprintf('%s can only be sent with a card payment (withCard).', $field));
                }
            }
        }

        $block = $this->block;
        if ($this->paymentMethod === PaymentMethod::PROMISSORY_NOTE) {
            if ($this->promissoryNotePlan !== null) {
                if (array_key_exists('noteCount', $block)) {
                    throw new ConfigurationException(
                        'The promissory-note plan is given twice: noteCount in the fields and withPromissoryNoteSchedule()/withPromissoryNoteCount(). Use one.'
                    );
                }
                $block += $this->promissoryNotePlan;
            }
            PromissoryNotePlan::assertBlock($block, (int) $this->data['amount']);
        } elseif ($this->promissoryNotePlan !== null) {
            throw new ConfigurationException('A promissory-note plan can only be sent with paymentMethod PROMISSORY_NOTE.');
        }

        $body = ['paymentMethod' => $this->paymentMethod] + $this->data;
        $body[PaymentMethod::blockName($this->paymentMethod)] = $block === [] ? new \stdClass() : $block;

        return $body;
    }
}
