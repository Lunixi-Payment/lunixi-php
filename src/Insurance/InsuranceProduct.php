<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * An insurance type offered for basket items (listProducts(), searchProducts()),
 * or sold without one (`basketItemIds()` empty).
 *
 * `fields()` is the live definition of what the type's quote request needs:
 * the type object named by `typeObject()` (e.g. `tickets`) and the insured,
 * for every insurance company open to you. A definition may carry
 * `requiredAt` (`QUOTE` | `POLICY`, required by all of them) and `requiredBy`
 * (`[['underwriterCode' => ?string, 'stage' => 'QUOTE'|'POLICY'], …]`, required
 * only by some or earlier); a company whose required field is missing is left
 * out of the quote (InsuranceQuoteSet::excludedOptions()). The provider's own
 * product code is never returned.
 *
 * `prefill()`, `providedFields()`, `quoteReady()` and `insuranceDataIssues()` say
 * what the basket items already fill in: from `insuranceContext` (plain, no
 * personal data) and from `insuranceData` (masked; a personal value is never
 * returned in clear).
 */
final class InsuranceProduct extends InsuranceResource
{
    /** Lunixi insurance type code, e.g. `TICKET_CANCELLATION`. */
    public function insuranceType(): string
    {
        return $this->str('insuranceType');
    }

    /** Name of the type's object in the quote body, e.g. `tickets`. */
    public function typeObject(): string
    {
        return $this->str('typeObject');
    }

    public function schemaVersion(): int
    {
        return $this->int('schemaVersion');
    }

    /** @return string[] Basket item ids the type covers. */
    public function basketItemIds(): array
    {
        return $this->strings('basketItemIds');
    }

    /** @return array<string,string> Text per language code. */
    public function title(): array
    {
        return $this->localized('title');
    }

    /** @return array<string,string> Text per language code. */
    public function description(): array
    {
        return $this->localized('description');
    }

    /** @return array<int,array<string,mixed>> The type's field definitions. */
    public function fields(): array
    {
        return $this->rows('fields', static fn (array $field): array => $field);
    }

    /**
     * Values taken from the items' `insuranceContext`, in the shape of the quote
     * body (e.g. `['tickets' => [['basketItemId' => …, 'transportMode' => 'TRAIN']]]`).
     * A field also sent in `insuranceData` is in providedFields() instead.
     *
     * @return array<string,mixed> Empty when there is nothing to prefill.
     */
    public function prefill(): array
    {
        return $this->object('prefill');
    }

    /**
     * The fields sent in the items' `insuranceData`, by path in the quote body
     * (e.g. `insured[0].identityNumber`, `tickets[0].pnr`), each with its value masked.
     *
     * @return array<int,array{path:string,masked:mixed}>
     */
    public function providedFields(): array
    {
        return $this->rows('providedFields', static fn (array $field): array => [
            'path' => is_string($field['path'] ?? null) ? $field['path'] : '',
            'masked' => $field['masked'] ?? null,
        ]);
    }

    /**
     * True when the prefilled and provided fields together meet what the quote
     * needs for at least one insurance company open to you: createQuote() with
     * `paymentIntentId` and no type fields can be priced, and the Checkout Form
     * prices the insurance on its own when it opens.
     */
    public function quoteReady(): bool
    {
        return ($this->data['quoteReady'] ?? null) === true;
    }

    /**
     * `insuranceData` that could not be used (not stored; the buyer is asked for
     * it). `field` is the path (`insuranceData` for the whole object), `reason` a
     * code such as `unknown_field`; never the value.
     *
     * @return array<int,array{basketItemId:string,field:string,reason:string}>
     */
    public function insuranceDataIssues(): array
    {
        return $this->rows('insuranceDataIssues', static fn (array $issue): array => [
            'basketItemId' => is_string($issue['basketItemId'] ?? null) ? $issue['basketItemId'] : '',
            'field' => is_string($issue['field'] ?? null) ? $issue['field'] : '',
            'reason' => is_string($issue['reason'] ?? null) ? $issue['reason'] : '',
        ]);
    }
}
