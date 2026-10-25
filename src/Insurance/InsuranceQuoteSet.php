<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * The answer of createQuote(): one or more priced options of an insurance
 * type, each with its own `quoteId` and expiry, and the insurance companies
 * the request could not be priced with.
 */
final class InsuranceQuoteSet extends InsuranceResource
{
    public function insuranceType(): string
    {
        return $this->str('insuranceType');
    }

    public function schemaVersion(): int
    {
        return $this->int('schemaVersion');
    }

    /** @return InsuranceQuoteOption[] */
    public function options(): array
    {
        return $this->rows('options', static fn (array $row): InsuranceQuoteOption => new InsuranceQuoteOption($row));
    }

    /**
     * Insurance companies left out of this quote; empty when every one was priced.
     *
     * @return InsuranceExcludedQuoteOption[]
     */
    public function excludedOptions(): array
    {
        return $this->rows(
            'excludedOptions',
            static fn (array $row): InsuranceExcludedQuoteOption => new InsuranceExcludedQuoteOption($row)
        );
    }
}
