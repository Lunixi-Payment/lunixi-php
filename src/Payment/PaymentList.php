<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A paginated list of payment intents (response of GET /api/v1/payments).
 */
final class PaymentList
{
    /** @var PaymentIntent[] */
    private array $items;
    private int $total;
    private int $page;
    private int $limit;
    private int $totalPages;

    /** @param array<string,mixed> $response The full list envelope. */
    public function __construct(array $response)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->items = array_map(
            static fn ($row): PaymentIntent => new PaymentIntent(is_array($row) ? $row : []),
            array_values($data)
        );
        $this->total = (int) ($response['total'] ?? count($this->items));
        $this->page = (int) ($response['page'] ?? 1);
        $this->limit = (int) ($response['limit'] ?? count($this->items));
        $this->totalPages = (int) ($response['totalPages'] ?? 1);
    }

    /** @return PaymentIntent[] */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function totalPages(): int
    {
        return $this->totalPages;
    }
}
