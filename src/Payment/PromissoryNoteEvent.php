<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * The note of a verified `payment.promissory_note.*` webhook.
 *
 *     $event = $lunixi->webhooks()->verify($rawBody, $headers, $secret);
 *     if (in_array($event->type(), PromissoryNoteEvents::ALL, true)) {
 *         $note = PromissoryNoteEvent::fromEvent($event);
 *     }
 *
 * The payment fields (`intentId`, `orderId`, `status` …) are the ones of every
 * `payment.*` event; the note is under `promissoryNote`. The event carries no
 * personal data.
 */
final class PromissoryNoteEvent
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,mixed> */
    private array $note;

    /** @param array<string,mixed> $data The event `data`. */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->note = isset($data['promissoryNote']) && is_array($data['promissoryNote']) ? $data['promissoryNote'] : [];
    }

    /** @throws ConfigurationException When the event is not a promissory-note event. */
    public static function fromEvent(WebhookEvent $event): self
    {
        if (!in_array($event->type(), PromissoryNoteEvents::ALL, true)) {
            throw new ConfigurationException(sprintf("Expected a 'payment.promissory_note.*' event, got '%s'.", $event->type()));
        }

        return new self($event->data());
    }

    /** The payment (`intentId`). */
    public function paymentId(): string
    {
        return (string) ($this->data['intentId'] ?? '');
    }

    public function orderId(): string
    {
        return (string) ($this->data['orderId'] ?? '');
    }

    public function serialNumber(): string
    {
        return (string) ($this->note['serialNumber'] ?? '');
    }

    /** 1-based position of the note in the plan. */
    public function sequence(): int
    {
        return (int) ($this->note['sequence'] ?? 0);
    }

    /** Minor units as a digit string. */
    public function amountMinor(): string
    {
        return (string) ($this->note['amountMinor'] ?? '');
    }

    public function dueDate(): string
    {
        return (string) ($this->note['dueDate'] ?? '');
    }

    /** PromissoryNoteStatus::PAID, PROTESTED or CANCELLED. */
    public function status(): string
    {
        return (string) ($this->note['status'] ?? '');
    }

    /** ISO-8601 time the provider reported the result. */
    public function occurredAt(): string
    {
        return (string) ($this->note['occurredAt'] ?? '');
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
