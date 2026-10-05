<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * A typed question the buyer answers on the payment page (no file uploads).
 *
 *   $field = (new PaymentLinkCustomField('invoice_no', 'Invoice no', PaymentLinkCustomField::TYPE_TEXT))
 *       ->withRequired()
 *       ->withMaxLength(32);
 *
 * `key` is `[a-z][a-z0-9_]{0,39}` and unique within the link; the buyer's
 * answer comes back under it on the attempt.
 */
final class PaymentLinkCustomField
{
    public const TYPE_TEXT = 'TEXT';
    public const TYPE_NUMBER = 'NUMBER';
    public const TYPE_EMAIL = 'EMAIL';
    public const TYPE_PHONE = 'PHONE';
    public const TYPE_DATE = 'DATE';
    public const TYPE_SELECT = 'SELECT';
    public const TYPE_CHECKBOX = 'CHECKBOX';
    /** Turkish national identity number. */
    public const TYPE_TCKN = 'TCKN';
    /** Turkish tax number. */
    public const TYPE_VKN = 'VKN';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_EMAIL,
        self::TYPE_PHONE,
        self::TYPE_DATE,
        self::TYPE_SELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_TCKN,
        self::TYPE_VKN,
    ];

    /** @var array<string,mixed> */
    private array $payload;

    public function __construct(string $key, string $label, string $fieldType)
    {
        if (trim($key) === '' || trim($label) === '') {
            throw new ConfigurationException("PaymentLinkCustomField 'key' and 'label' are required.");
        }
        if (!in_array($fieldType, self::TYPES, true)) {
            throw new ConfigurationException(
                "PaymentLinkCustomField 'fieldType' must be one of " . implode(', ', self::TYPES) . "; got '{$fieldType}'."
            );
        }

        $this->payload = ['key' => $key, 'label' => $label, 'fieldType' => $fieldType];
    }

    public function withRequired(bool $required = true): self
    {
        $this->payload['required'] = $required;
        return $this;
    }

    /**
     * Choices of a SELECT field (1–20).
     *
     * @param string[] $options
     */
    public function withOptions(array $options): self
    {
        foreach ($options as $option) {
            if (!is_string($option)) {
                throw new ConfigurationException("PaymentLinkCustomField 'options' must be strings.");
            }
        }
        $this->payload['options'] = array_values($options);
        return $this;
    }

    /** TEXT only: 1–500 characters. */
    public function withMaxLength(int $maxLength): self
    {
        $this->payload['maxLength'] = $maxLength;
        return $this;
    }

    public function withHelpText(string $helpText): self
    {
        $this->payload['helpText'] = $helpText;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
