<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body of POST /api/v1/payments/split (SplitPaymentDto): one payment funded by
 * several legs (PaymentClient::createSplitPayment()).
 *
 *   - addCardLeg($cardToken, $amount, $force3D, $installment, $quoteToken): a
 *     stored card of `ownerRef`. `$force3D` runs the leg with 3D Secure (then
 *     withCallbackUrl() is required). From 2 installments `$quoteToken` is
 *     required: a quote from installmentOptions() with this leg's amount.
 *   - addMethodLeg($method, $providerCode, $amount, $fields): a non-card method
 *     with the same block as direct/payment. `$providerCode` is required: a
 *     provider with splitEligible() from listPaymentMethods(). A
 *     promissory-note leg gives the number of notes as `noteCount` in
 *     `$fields` (no schedule).
 *
 * Leg amounts are minor units and must add up to `totalAmount`. The order in
 * which legs are added does not matter: the platform sets the order of the
 * steps (credit approval, card holds, reversible charges, the non-reversible
 * charge last). The rules are checked before sending, with the gateway's
 * limits (same as the Node SDK's `assertSplitPaymentPayload`).
 */
final class SplitPaymentRequest
{
    /** Body fields of POST /payments/split (gateway SplitPaymentDto). */
    public const FIELDS = ['totalAmount', 'currency', 'ownerRef', 'legs', 'orderId', 'ownerScope', 'callbackUrl'];

    /** Fields of one leg (gateway SplitPaymentLegDto). */
    public const LEG_FIELDS = [
        'paymentMethod', 'cardToken', 'amount', 'force3D', 'installment', 'installmentQuoteToken',
        'mobileBilling', 'wallet', 'promissoryNote',
    ];

    public const MIN_LEGS = 1;
    public const MAX_LEGS = 5;
    public const MAX_INSTALLMENT = 36;

    private const CARD_TOKEN_MAX_LENGTH = 256;
    private const QUOTE_TOKEN_MAX_LENGTH = 4096;
    private const CALLBACK_URL_MAX_LENGTH = 2048;

    /** @var array<string,mixed> */
    private array $data;

    /** @var list<array<string,mixed>> */
    private array $legs = [];

    /**
     * @param int    $totalAmount Minor units; the sum of the leg amounts.
     * @param string $ownerRef    Your customer's card owner key (cardUserKey); required also without a card leg.
     */
    public function __construct(int $totalAmount, string $currency, string $ownerRef)
    {
        if ($totalAmount < 1) {
            throw new ConfigurationException("SplitPaymentRequest 'totalAmount' must be a positive integer (minor units).");
        }
        $currency = strtoupper($currency);
        if (!Currency::isSupported($currency)) {
            throw new ConfigurationException("Unsupported currency '{$currency}'.");
        }
        if (trim($ownerRef) === '') {
            throw new ConfigurationException("SplitPaymentRequest 'ownerRef' is required.");
        }

        $this->data = [
            'totalAmount' => $totalAmount,
            'currency' => $currency,
            'ownerRef' => $ownerRef,
        ];
    }

    public function withOrderId(string $orderId): self
    {
        if (trim($orderId) === '') {
            throw new ConfigurationException("SplitPaymentRequest 'orderId' must not be empty.");
        }
        $this->data['orderId'] = $orderId;

        return $this;
    }

    /** Where the buyer returns after a card leg's 3D Secure step. Required when a card leg uses 3D Secure. */
    public function withCallbackUrl(string $url): self
    {
        if ($url === '' || strlen($url) > self::CALLBACK_URL_MAX_LENGTH || preg_match('#^https?://#i', $url) !== 1) {
            throw new ConfigurationException(sprintf('callbackUrl must be an http(s) URL of at most %d characters.', self::CALLBACK_URL_MAX_LENGTH));
        }
        $this->data['callbackUrl'] = $url;

        return $this;
    }

    /** A stored card leg (`cardToken` of a card saved for `ownerRef`). */
    public function addCardLeg(
        string $cardToken,
        int $amount,
        bool $force3D = false,
        ?int $installment = null,
        ?string $installmentQuoteToken = null
    ): self {
        $path = sprintf('legs[%d]', count($this->legs));
        if (trim($cardToken) === '' || strlen($cardToken) > self::CARD_TOKEN_MAX_LENGTH) {
            throw new ConfigurationException(sprintf('%s.cardToken must be a non-empty string of at most %d characters.', $path, self::CARD_TOKEN_MAX_LENGTH));
        }
        foreach ($this->legs as $leg) {
            if (($leg['cardToken'] ?? null) === $cardToken) {
                throw new ConfigurationException(sprintf('%s: the same card is used in more than one leg.', $path));
            }
        }
        $leg = ['cardToken' => $cardToken, 'amount' => self::requireAmount($amount, $path)];
        if ($force3D) {
            $leg['force3D'] = true;
        }
        if ($installment !== null) {
            if ($installment < 1 || $installment > self::MAX_INSTALLMENT) {
                throw new ConfigurationException(sprintf('%s.installment must be from 1 to %d.', $path, self::MAX_INSTALLMENT));
            }
            $leg['installment'] = $installment;
        }
        if ($installmentQuoteToken !== null) {
            if (trim($installmentQuoteToken) === '' || strlen($installmentQuoteToken) > self::QUOTE_TOKEN_MAX_LENGTH) {
                throw new ConfigurationException(sprintf('%s.installmentQuoteToken must be a non-empty string of at most %d characters.', $path, self::QUOTE_TOKEN_MAX_LENGTH));
            }
            $leg['installmentQuoteToken'] = $installmentQuoteToken;
        }
        if ($installment !== null && $installment >= 2 && $installmentQuoteToken === null) {
            throw new ConfigurationException(sprintf(
                '%s: an installment of 2 or more needs installmentQuoteToken (installmentOptions() with this leg\'s amount).',
                $path
            ));
        }
        $this->legs[] = $leg;

        return $this;
    }

    /**
     * A non-card leg. `$fields` are the provider's `inputFields` values (for
     * Faturana Yansıt `['gsmNumber' => '+905321234567']`), as in
     * DirectMethodPaymentRequest::withMethodFields().
     *
     * @param array<string,mixed> $fields
     */
    public function addMethodLeg(string $paymentMethod, string $providerCode, int $amount, array $fields = []): self
    {
        $path = sprintf('legs[%d]', count($this->legs));
        $code = PaymentMethod::normalize($paymentMethod);
        if ($code === PaymentMethod::CARD) {
            throw new ConfigurationException('Use addCardLeg() for a card leg.');
        }
        $blockName = PaymentMethod::blockName($code);
        if (!in_array($blockName, PaymentMethod::METHOD_BLOCK_FIELDS, true)) {
            throw new ConfigurationException(sprintf(
                '%s: paymentMethod %s cannot be a split payment leg; a non-card leg carries one of the blocks %s.',
                $path,
                $code,
                implode(', ', PaymentMethod::METHOD_BLOCK_FIELDS)
            ));
        }
        $providerCode = PaymentMethod::requireProviderCode($providerCode);
        foreach ($this->legs as $leg) {
            $other = $leg[PaymentMethod::blockName((string) ($leg['paymentMethod'] ?? PaymentMethod::CARD))] ?? null;
            if (is_array($other) && ($other['providerCode'] ?? null) === $providerCode) {
                throw new ConfigurationException(sprintf('%s: provider %s is used in more than one leg; one leg per provider.', $path, $providerCode));
            }
        }
        $block = PaymentMethod::requireFields($fields, $path . '.' . $blockName);
        if ($code === PaymentMethod::PROMISSORY_NOTE) {
            if (array_key_exists('schedule', $block)) {
                throw new ConfigurationException(sprintf('%s.%s.schedule cannot be sent in a split payment leg; give the number of notes with noteCount.', $path, $blockName));
            }
            $block['noteCount'] = PromissoryNotePlan::requireNoteCount($block['noteCount'] ?? null, $path . '.' . $blockName . '.noteCount');
        }

        $this->legs[] = [
            'paymentMethod' => $code,
            'amount' => self::requireAmount($amount, $path),
            $blockName => ['providerCode' => $providerCode] + $block,
        ];

        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $count = count($this->legs);
        if ($count < self::MIN_LEGS || $count > self::MAX_LEGS) {
            throw new ConfigurationException(sprintf('A split payment has %d to %d legs; %d given.', self::MIN_LEGS, self::MAX_LEGS, $count));
        }
        $sum = 0;
        $force3D = false;
        foreach ($this->legs as $leg) {
            $sum += (int) $leg['amount'];
            $force3D = $force3D || (($leg['force3D'] ?? false) === true);
        }
        if ($sum !== $this->data['totalAmount']) {
            throw new ConfigurationException(sprintf(
                'The legs add up to %d but totalAmount is %d; they must be equal.',
                $sum,
                $this->data['totalAmount']
            ));
        }
        if ($force3D && !isset($this->data['callbackUrl'])) {
            throw new ConfigurationException('callbackUrl is required when a card leg uses 3D Secure (withCallbackUrl()).');
        }

        return $this->data + ['legs' => $this->legs];
    }

    private static function requireAmount(int $amount, string $path): int
    {
        if ($amount < 1) {
            throw new ConfigurationException(sprintf('%s.amount must be a positive integer (minor units).', $path));
        }

        return $amount;
    }
}
