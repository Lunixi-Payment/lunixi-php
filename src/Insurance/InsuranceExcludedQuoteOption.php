<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * An insurance company a quote request could not be priced with
 * (InsuranceQuoteSet::excludedOptions()). Only this option is left out;
 * complete the fields and quote again. No value and no provider code is
 * returned, only field paths.
 */
final class InsuranceExcludedQuoteOption extends InsuranceResource
{
    /** Null when no company is reported. */
    public function underwriter(): ?InsuranceUnderwriter
    {
        $underwriter = $this->object('underwriter');

        return $underwriter === [] ? null : new InsuranceUnderwriter($underwriter);
    }

    public function provider(): InsuranceProvider
    {
        return new InsuranceProvider($this->object('provider'));
    }

    /** Lunixi error code, `insurance.validation.invalid` for missing fields. */
    public function errorCode(): string
    {
        return $this->str('errorCode');
    }

    /**
     * Field paths and reasons, e.g. `['field' => 'insured[0].birthDate', 'reason' => 'required']`.
     *
     * @return array<int,array{field:string,reason:string}>
     */
    public function errors(): array
    {
        return $this->rows('errors', static fn (array $error): array => [
            'field' => is_scalar($error['field'] ?? null) ? (string) $error['field'] : '',
            'reason' => is_scalar($error['reason'] ?? null) ? (string) $error['reason'] : '',
        ]);
    }
}
