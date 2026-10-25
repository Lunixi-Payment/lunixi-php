<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Http\Envelope;

/**
 * A page of PaymentClient::listSplitPayments(). Pass endCursor() as `after` to
 * read the next page.
 */
final class SplitPaymentList
{
    /** @var array<string,mixed> */
    private array $payload;

    /** @param array<string,mixed> $response The full list envelope. */
    public function __construct(array $response)
    {
        $this->payload = Envelope::data($response);
    }

    /** @return SplitPaymentDetail[] Items carry no legs; read one with getSplitPayment(). */
    public function items(): array
    {
        $items = is_array($this->payload['items'] ?? null) ? $this->payload['items'] : [];

        return array_values(array_map(
            static fn ($item): SplitPaymentDetail => new SplitPaymentDetail(is_array($item) ? $item : []),
            $items
        ));
    }

    public function hasNextPage(): bool
    {
        $pageInfo = is_array($this->payload['pageInfo'] ?? null) ? $this->payload['pageInfo'] : [];

        return ($pageInfo['hasNextPage'] ?? false) === true;
    }

    /** Opaque cursor of the next page; null on the last page. */
    public function endCursor(): ?string
    {
        $pageInfo = is_array($this->payload['pageInfo'] ?? null) ? $this->payload['pageInfo'] : [];
        $cursor = $pageInfo['endCursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
