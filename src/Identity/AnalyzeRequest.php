<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Identity;

/**
 * Body for `POST /api/v1/identity/live-sessions/analyze-sync` (server-side
 * synchronous identity analysis). Sub-objects are passed as arrays so the SDK
 * stays forward-compatible with the engine's evolving signal set. Provide the
 * `account` context (account id/key) so the result can be linked across accounts.
 *
 *   $req = (new AnalyzeRequest())
 *       ->withSubject('user', (string) $wpUserId)
 *       ->withCustomer(['email' => $email])
 *       ->withDevice(['deviceId' => $fingerprintId, 'ipAddress' => $ip])
 *       ->withAccount(['accountId' => (string) $wpUserId]);
 */
final class AnalyzeRequest
{
    /** @var array<string,mixed> */
    private array $body = [];

    public function withSubject(string $subjectType, string $subjectId): self
    {
        $this->body['subjectType'] = $subjectType;
        $this->body['subjectId'] = $subjectId;
        return $this;
    }

    public function withProductContext(string $context): self
    {
        $this->body['productContext'] = $context;
        return $this;
    }

    public function withIntegrationMode(string $mode): self
    {
        $this->body['integrationMode'] = $mode;
        return $this;
    }

    /** @param array<string,mixed> $customer email, phoneNumber, fullName, … */
    public function withCustomer(array $customer): self
    {
        $this->body['customer'] = $customer;
        return $this;
    }

    /** @param array<string,mixed> $device deviceId/fingerprintId, ipAddress, userAgent, … */
    public function withDevice(array $device): self
    {
        $this->body['device'] = $device;
        return $this;
    }

    /** @param array<string,mixed> $session */
    public function withSession(array $session): self
    {
        $this->body['session'] = $session;
        return $this;
    }

    /** @param array<string,mixed> $source origin, referrer, entryPoint */
    public function withSource(array $source): self
    {
        $this->body['source'] = $source;
        return $this;
    }

    /** @param array<string,mixed> $account accountId, accountKey, counterpartyAccountId */
    public function withAccount(array $account): self
    {
        $this->body['account'] = $account;
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->body['metadata'] = $metadata;
        return $this;
    }

    public function withPaymentId(string $paymentId): self
    {
        $this->body['paymentId'] = $paymentId;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->body;
    }
}
