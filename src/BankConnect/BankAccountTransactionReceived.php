<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * The `data` of a verified `bank_account.transaction.received` webhook.
 *
 *     $event = $lunixi->webhooks()->verify($rawBody, $headers, $secret);
 *     if ($event->type() === BankConnectEvents::TRANSACTION_RECEIVED) {
 *         $txn = BankAccountTransactionReceived::fromEvent($event);
 *     }
 *
 * Amounts are integers in minor units; `amountMinor` is always positive and
 * `direction` gives the sign. `valueAt`, `description`, the masked
 * counterparty fields and `receiptNo` are null when the bank did not send
 * them. getTransaction($txn->transactionId()) returns the stored transaction
 * with its detail fields.
 */
final class BankAccountTransactionReceived extends BankConnectResource
{
    private string $environment;

    /**
     * @param array<string,mixed> $data        The event `data`.
     * @param string              $environment `TEST` or `LIVE` (the delivery envelope's `environment`).
     */
    public function __construct(array $data, string $environment = '')
    {
        parent::__construct($data);
        $this->environment = $environment;
    }

    /** @throws ConfigurationException When the event is of another type. */
    public static function fromEvent(WebhookEvent $event): self
    {
        if ($event->type() !== BankConnectEvents::TRANSACTION_RECEIVED) {
            throw new ConfigurationException(sprintf(
                "Expected a '%s' event, got '%s'.",
                BankConnectEvents::TRANSACTION_RECEIVED,
                $event->type()
            ));
        }
        $environment = $event->envelope()['environment'] ?? '';

        return new self($event->data(), is_string($environment) ? $environment : '');
    }

    /** `TEST` or `LIVE`: TEST events come only from test-environment accounts. */
    public function environment(): string
    {
        return $this->environment;
    }

    public function transactionId(): string
    {
        return $this->str('transactionId');
    }

    public function accountId(): string
    {
        return $this->str('accountId');
    }

    public function connectionId(): string
    {
        return $this->str('connectionId');
    }

    public function bankEftCode(): string
    {
        return $this->str('bankEftCode');
    }

    public function bankName(): string
    {
        return $this->str('bankName');
    }

    /** One of the BankTransactionDirection constants. */
    public function direction(): string
    {
        return $this->str('direction');
    }

    public function isCredit(): bool
    {
        return $this->direction() === BankTransactionDirection::CREDIT;
    }

    public function amountMinor(): int
    {
        return $this->int('amountMinor');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    public function bookedAt(): string
    {
        return $this->str('bookedAt');
    }

    public function valueAt(): ?string
    {
        return $this->strOrNull('valueAt');
    }

    public function receivedAt(): string
    {
        return $this->str('receivedAt');
    }

    /** One of the BankTransactionType constants. */
    public function canonicalType(): string
    {
        return $this->str('canonicalType');
    }

    public function description(): ?string
    {
        return $this->strOrNull('description');
    }

    public function counterpartyNameMasked(): ?string
    {
        return $this->strOrNull('counterpartyNameMasked');
    }

    public function counterpartyIbanMasked(): ?string
    {
        return $this->strOrNull('counterpartyIbanMasked');
    }

    public function receiptNo(): ?string
    {
        return $this->strOrNull('receiptNo');
    }
}
