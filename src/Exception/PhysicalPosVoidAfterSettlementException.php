<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * 409 `physical_pos.payment.void_after_settlement`: the payment is already in
 * a device end-of-day, so it can no longer be voided. Return the amount with a
 * `REFUND` reversal on the same payment (with a new Idempotency-Key).
 *
 * {@see getAllowedOperation()} is `details.allowedOperation` (`REFUND`), or
 * null when the answer did not carry it.
 */
class PhysicalPosVoidAfterSettlementException extends ApiException
{
    public const CODE = 'physical_pos.payment.void_after_settlement';

    private ?string $allowedOperation;

    /**
     * @param array<string,mixed>|null  $responseBody
     * @param array<string,string>|null $responseHeaders
     */
    public function __construct(
        string $message,
        ?string $allowedOperation,
        int $statusCode = 409,
        ?array $responseBody = null,
        ?string $requestId = null,
        ?\Throwable $previous = null,
        ?array $responseHeaders = null
    ) {
        parent::__construct($message, $statusCode, self::CODE, $responseBody, $requestId, $previous, $responseHeaders);
        $this->allowedOperation = $allowedOperation;
    }

    /** The operation the payment still allows (`REFUND`), or null. */
    public function getAllowedOperation(): ?string
    {
        return $this->allowedOperation;
    }
}
