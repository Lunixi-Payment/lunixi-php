<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Sdk\Webhook\WebhookVerifier;

/**
 * A `physical_pos.*` webhook. It can only be built from the raw delivery: the
 * signature is checked with WebhookVerifier inside {@see verify()} — there is
 * no constructor or array path that skips it.
 *
 *     $pos = PhysicalPosClient::parseWebhookEvent($rawBody, $headers, $secret);
 *     // dedupe on $pos->id(); ignore it when $pos->historySeq() <= the last applied for $pos->paymentId()
 *
 * The payload is the envelope `data`; a `payloadJson` envelope (object or JSON
 * text) is read the same way. A reversal's payload carries `original`
 * (`{paymentId, origin}`, see original()); a device sale
 * (`physical_pos.payment.device_sale`, isDeviceSale()) also carries `origin`
 * and `deviceOccurredAt`. Webhook amounts are STRINGS in minor units
 * (`"4500"`), matching the HTTP API. A payment event carries only the
 * masked card (`tender.cardMasked`, `cardBrand`, `entryMode`) — no bank
 * references, items or personal data.
 */
final class PhysicalPosWebhookEvent
{
    public const CATEGORY_PAYMENT = 'payment';
    public const CATEGORY_FISCAL_DOCUMENT = 'fiscal_document';
    public const CATEGORY_OTHER = 'other';

    private string $id;

    private string $type;

    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,mixed> */
    private array $raw;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $raw
     */
    private function __construct(string $id, string $type, array $data, array $raw)
    {
        PhysicalPosMoney::validate($data);
        $this->id = $id;
        $this->type = $type;
        $this->data = $data;
        $this->raw = $raw;
    }

    /**
     * Verifies the delivery with WebhookVerifier (HMAC over the exact raw body,
     * timestamp window, event id header) and reads the verified envelope.
     *
     * @param array<string,string> $headers
     * @throws WebhookVerificationException When the signature, timestamp or headers do not verify.
     * @throws ConfigurationException When it is not a physical POS event or has no payload.
     */
    public static function verify(
        string $rawBody,
        array $headers,
        string $secret,
        ?int $tolerance = WebhookVerifier::DEFAULT_TOLERANCE_SECONDS
    ): self {
        $event = (new WebhookVerifier())->verify($rawBody, $headers, $secret, $tolerance);
        $envelope = $event->envelope();

        $type = self::text($envelope, 'type') ?? self::text($envelope, 'eventType') ?? $event->type();
        if (strpos($type, 'physical_pos.') !== 0) {
            throw new ConfigurationException("Expected a physical_pos.* event, got '{$type}'.");
        }
        $id = self::text($envelope, 'id') ?? self::text($envelope, 'eventId') ?? $event->id();

        return new self($id, $type, self::payload($envelope), $envelope);
    }

    /** Event id; dedupe on it (the same transition always carries the same id). */
    public function id(): string
    {
        return $this->id;
    }

    /** One of PhysicalPosEvents (open set). */
    public function type(): string
    {
        return $this->type;
    }

    /** `payment`, `fiscal_document` or `other`. */
    public function category(): string
    {
        if (strpos($this->type, 'physical_pos.payment.') === 0) {
            return self::CATEGORY_PAYMENT;
        }
        if (strpos($this->type, 'physical_pos.fiscal_document.') === 0) {
            return self::CATEGORY_FISCAL_DOCUMENT;
        }

        return self::CATEGORY_OTHER;
    }

    public function isPaymentEvent(): bool
    {
        return $this->category() === self::CATEGORY_PAYMENT;
    }

    /** `TEST` or `LIVE`, or null when the envelope has none. */
    public function environment(): ?string
    {
        return self::text($this->raw, 'environment');
    }

    public function createdAt(): ?string
    {
        return self::text($this->raw, 'createdAt') ?? self::text($this->raw, 'occurredAt') ?? self::text($this->raw, 'timestamp');
    }

    /** Increases with every status change of the same payment / document; null when absent. */
    public function historySeq(): ?int
    {
        $value = $this->data['historySeq'] ?? null;

        return is_int($value) ? $value : null;
    }

    /** True for `physical_pos.payment.device_sale`: a sale made directly on the device. */
    public function isDeviceSale(): bool
    {
        return $this->type === PhysicalPosEvents::PAYMENT_DEVICE_SALE;
    }

    /**
     * The original payment of a reversal event: `['paymentId' => …, 'origin' => …]`
     * (`origin`: `API` or `DEVICE_DIRECT`) when `data.original` carries both as
     * non-empty strings (other keys are dropped); null on a root payment.
     *
     * @return array{paymentId:string,origin:string}|null
     */
    public function original(): ?array
    {
        return PhysicalPosRequest::webhookOriginal($this->data);
    }

    /** `paymentId` of the payload (payment and fiscal document events), or null. */
    public function paymentId(): ?string
    {
        return self::text($this->data, 'paymentId');
    }

    /** @return array<string,mixed> The event payload. */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> The whole delivered envelope. */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @param array<string,mixed> $envelope
     * @return array<string,mixed>
     */
    private static function payload(array $envelope): array
    {
        $data = $envelope['data'] ?? null;
        if (is_array($data) && $data !== []) {
            return $data;
        }
        $json = $envelope['payloadJson'] ?? null;
        if (is_string($json) && trim($json) !== '') {
            $json = json_decode($json, true);
        }
        if (is_array($json) && $json !== []) {
            return $json;
        }

        throw new ConfigurationException('The physical POS webhook has no payload (data or payloadJson).');
    }

    /** @param array<string,mixed> $source */
    private static function text(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
