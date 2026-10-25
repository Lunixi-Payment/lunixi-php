<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * One priced option of a quote (createQuote(), getQuote()). Each option has
 * its own `quoteId`, terms and expiry; only a QUOTED option that has not
 * expired becomes an order.
 */
final class InsuranceQuoteOption extends InsuranceResource
{
    /** `insq_…`. */
    public function quoteId(): string
    {
        return $this->str('quoteId');
    }

    public function insuranceType(): string
    {
        return $this->str('insuranceType');
    }

    public function schemaVersion(): int
    {
        return $this->int('schemaVersion');
    }

    public function title(): ?string
    {
        return $this->strOrNull('title');
    }

    /** Minor units. */
    public function premiumAmount(): int
    {
        return $this->int('premiumAmount');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    /** One of the InsurancePremiumCollectedBy constants. */
    public function premiumCollectedBy(): string
    {
        return $this->str('premiumCollectedBy');
    }

    public function provider(): InsuranceProvider
    {
        return new InsuranceProvider($this->object('provider'));
    }

    /** @return string[] Basket item ids the option covers. */
    public function basketItemIds(): array
    {
        return $this->strings('basketItemIds');
    }

    /** @return string[] */
    public function coverageSummary(): array
    {
        return $this->strings('coverageSummary');
    }

    public function terms(): InsuranceTerms
    {
        return new InsuranceTerms($this->object('terms'));
    }

    public function underwriterName(): ?string
    {
        return $this->strOrNull('underwriterName');
    }

    /** The insurance company, apart from provider(); null when none is reported. */
    public function underwriter(): ?InsuranceUnderwriter
    {
        $underwriter = $this->object('underwriter');

        return $underwriter === [] ? null : new InsuranceUnderwriter($underwriter);
    }

    public function expiresAt(): string
    {
        return $this->str('expiresAt');
    }

    /** One of the InsuranceQuoteStatus constants. */
    public function status(): string
    {
        return $this->str('status');
    }

    /** The payment whose basket was quoted; null for a quote made from `context.items`. */
    public function paymentIntentId(): ?string
    {
        return $this->strOrNull('paymentIntentId');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }
}
