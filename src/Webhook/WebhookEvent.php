<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Webhook;

/**
 * A verified inbound webhook. Returned by WebhookVerifier only AFTER the
 * signature, timestamp and body have been validated — receiving one means the
 * payload is authentic and safe to act on (still dedupe by id() — at-least-once).
 */
final class WebhookEvent
{
    private string $id;
    private string $type;
    private string $timestamp;
    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,mixed> */
    private array $envelope;

    /**
     * @param array<string,mixed> $data     The event-specific payload (envelope `data`).
     * @param array<string,mixed> $envelope The full delivered envelope (defaults to $data for flat bodies).
     */
    public function __construct(string $id, string $type, string $timestamp, array $data, array $envelope = [])
    {
        $this->id = $id;
        $this->type = $type;
        $this->timestamp = $timestamp;
        $this->data = $data;
        $this->envelope = $envelope === [] ? $data : $envelope;
    }

    /** Unique event id (from `x-lunixi-event-id`). Use this to dedupe — delivery is at-least-once. */
    public function id(): string
    {
        return $this->id;
    }

    /** Event type, e.g. "payment.succeeded" (from `x-lunixi-event-type`). */
    public function type(): string
    {
        return $this->type;
    }

    /** ISO-8601 signature timestamp (from `x-lunixi-signature-timestamp`). */
    public function timestamp(): string
    {
        return $this->timestamp;
    }

    /** @return array<string,mixed> The event-specific payload (the envelope `data`). */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> The full delivered envelope (id, type, environment, data, …). */
    public function envelope(): array
    {
        return $this->envelope;
    }

    /** @return array<string,mixed> Alias of data() — the event-specific payload. */
    public function payload(): array
    {
        return $this->data;
    }

    /** Whether this event type matches a subscription pattern (supports a trailing ".*" wildcard). */
    public function matches(string $pattern): bool
    {
        if ($pattern === $this->type) {
            return true;
        }
        if (substr($pattern, -2) === '.*') {
            $prefix = substr($pattern, 0, -1); // keep the dot: "payment."
            return strpos($this->type, $prefix) === 0;
        }
        return false;
    }
}
