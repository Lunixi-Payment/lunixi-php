<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * The `data` of a verified `bank_transfer.*` webhook.
 *
 *     $event = $lunixi->webhooks()->verify($rawBody, $headers, $secret);
 *     if (in_array($event->type(), BankTransferEvents::ALL, true)) {
 *         $transfer = BankTransferEvent::fromEvent($event);
 *     }
 *
 * Fields without a value are null. The event says what happened; read the
 * current state with BankTransferClient::get($transfer->transferId()).
 */
final class BankTransferEvent extends BankConnectResource
{
    private string $type;

    private string $environment;

    /**
     * @param array<string,mixed> $data        The event `data`.
     * @param string              $type        One of the BankTransferEvents constants.
     * @param string              $environment `TEST` or `LIVE` (the delivery envelope's `environment`).
     */
    public function __construct(array $data, string $type = '', string $environment = '')
    {
        parent::__construct($data);
        $this->type = $type;
        $this->environment = $environment;
    }

    /** @throws ConfigurationException When the event is not a `bank_transfer.*` event. */
    public static function fromEvent(WebhookEvent $event): self
    {
        if (!in_array($event->type(), BankTransferEvents::ALL, true)) {
            throw new ConfigurationException(sprintf(
                "Expected a '%s' event, got '%s'.",
                BankTransferEvents::ALL_EVENTS,
                $event->type()
            ));
        }
        $environment = $event->envelope()['environment'] ?? '';

        return new self($event->data(), $event->type(), is_string($environment) ? $environment : '');
    }

    /** One of the BankTransferEvents constants. */
    public function type(): string
    {
        return $this->type;
    }

    /** `TEST` or `LIVE`. */
    public function environment(): string
    {
        return $this->environment;
    }

    /** Same as BankTransfer::id(). */
    public function transferId(): string
    {
        return $this->str('transferId');
    }

    public function connectionId(): string
    {
        return $this->str('connectionId');
    }

    public function accountId(): string
    {
        return $this->str('accountId');
    }

    public function bankEftCode(): string
    {
        return $this->str('bankEftCode');
    }

    public function bankName(): string
    {
        return $this->str('bankName');
    }

    /** One of the BankTransferStatus constants at the time of the event. */
    public function status(): string
    {
        return $this->str('status');
    }

    public function rail(): ?string
    {
        return $this->strOrNull('rail');
    }

    public function amountMinor(): int
    {
        return $this->int('amountMinor');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    public function receiverIbanMasked(): string
    {
        return $this->str('receiverIbanMasked');
    }

    public function receiverNameMasked(): string
    {
        return $this->str('receiverNameMasked');
    }

    public function paymentPurpose(): ?string
    {
        return $this->strOrNull('paymentPurpose');
    }

    public function description(): ?string
    {
        return $this->strOrNull('description');
    }

    public function failureCode(): ?string
    {
        return $this->strOrNull('failureCode');
    }

    public function idempotencyKey(): string
    {
        return $this->str('idempotencyKey');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }

    public function completedAt(): ?string
    {
        return $this->strOrNull('completedAt');
    }

    public function failedAt(): ?string
    {
        return $this->strOrNull('failedAt');
    }

    public function returnedAt(): ?string
    {
        return $this->strOrNull('returnedAt');
    }
}
