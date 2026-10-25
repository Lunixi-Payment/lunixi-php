<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A field a provider asks the buyer for (`providers[].inputFields[]` of
 * listPaymentMethods(), and `action.input.fields[]` of an INPUT action). Send
 * the value in the method block (or confirm `fields`) under key().
 */
final class MethodInputField
{
    public const TYPE_PHONE = 'PHONE';
    public const TYPE_TCKN = 'TCKN';
    public const TYPE_EMAIL = 'EMAIL';
    public const TYPE_DATE = 'DATE';
    public const TYPE_TEXT = 'TEXT';
    public const TYPE_NUMBER = 'NUMBER';
    public const TYPE_SELECT = 'SELECT';
    public const TYPE_CONSENT = 'CONSENT';
    /** 10-digit Turkish tax number with checksum. */
    public const TYPE_VKN = 'VKN';
    /** `['provinceCode' => …, 'districtCode' => …, 'neighborhoodCode' => …, 'line' => …]`; codes from ReferenceClient. */
    public const TYPE_ADDRESS = 'ADDRESS';
    /** A list of associative arrays, each with the fields of itemFields(). */
    public const TYPE_GROUP = 'GROUP';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function key(): string
    {
        return (string) ($this->data['key'] ?? '');
    }

    /** One of the TYPE_* constants. */
    public function type(): string
    {
        return (string) ($this->data['type'] ?? '');
    }

    public function isRequired(): bool
    {
        return ($this->data['required'] ?? false) === true;
    }

    /** Label to show the buyer, `tr` or `en`. */
    public function label(string $language = 'tr'): string
    {
        $label = $this->data['label'] ?? [];

        return is_array($label) && isset($label[$language]) ? (string) $label[$language] : '';
    }

    /** Buyer data used when the field is omitted (still validated), e.g. `buyer.gsmNumber`. */
    public function prefill(): ?string
    {
        return self::text($this->data['prefill'] ?? null);
    }

    /** Personal data: the gateway does not log, store in metadata or return the value. */
    public function isPii(): bool
    {
        return ($this->data['pii'] ?? false) === true;
    }

    public function pattern(): ?string
    {
        return self::text($this->data['pattern'] ?? null);
    }

    public function minLength(): ?int
    {
        return isset($this->data['minLength']) ? (int) $this->data['minLength'] : null;
    }

    public function maxLength(): ?int
    {
        return isset($this->data['maxLength']) ? (int) $this->data['maxLength'] : null;
    }

    /**
     * SELECT options: value => Turkish label.
     *
     * @return array<string,string>
     */
    public function options(): array
    {
        $options = [];
        foreach (is_array($this->data['options'] ?? null) ? $this->data['options'] : [] as $option) {
            if (is_array($option) && isset($option['value'])) {
                $options[(string) $option['value']] = (string) ($option['label']['tr'] ?? $option['value']);
            }
        }

        return $options;
    }

    /** CONSENT: agreement URL. */
    public function url(): ?string
    {
        return self::text($this->data['url'] ?? null);
    }

    /**
     * Conditional requirement: required when the sibling SELECT field `field`
     * equals `equals`; otherwise leave the field out. Null when unconditional.
     *
     * @return array{field:string,equals:string}|null
     */
    public function requiredWhen(): ?array
    {
        $condition = $this->data['requiredWhen'] ?? null;
        if (!is_array($condition) || !isset($condition['field'], $condition['equals'])) {
            return null;
        }

        return ['field' => (string) $condition['field'], 'equals' => (string) $condition['equals']];
    }

    /**
     * GROUP: the fields of each item; empty for other types.
     *
     * @return MethodInputField[]
     */
    public function itemFields(): array
    {
        $fields = [];
        foreach (is_array($this->data['itemFields'] ?? null) ? $this->data['itemFields'] : [] as $field) {
            if (is_array($field)) {
                $fields[] = new self($field);
            }
        }

        return $fields;
    }

    /** GROUP: least items; null = none. */
    public function minItems(): ?int
    {
        return isset($this->data['minItems']) ? (int) $this->data['minItems'] : null;
    }

    /** GROUP: most items; null = the platform cap (10). */
    public function maxItems(): ?int
    {
        return isset($this->data['maxItems']) ? (int) $this->data['maxItems'] : null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    /** @param mixed $value */
    private static function text($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
