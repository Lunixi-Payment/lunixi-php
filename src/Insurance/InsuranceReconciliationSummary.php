<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Reconciliation totals for a period (getReconciliationSummary()), grouped by
 * provider connection, currency and `premiumCollectedBy`. The totals equal the
 * sum of the rows of listReconciliationOrders() and exportReconciliation() for
 * the same filter.
 */
final class InsuranceReconciliationSummary extends InsuranceResource
{
    /**
     * The half-open period `[from, to)` on the order creation time.
     *
     * @return array{from:string,to:string}
     */
    public function period(): array
    {
        $period = $this->object('period');

        return [
            'from' => is_string($period['from'] ?? null) ? $period['from'] : '',
            'to' => is_string($period['to'] ?? null) ? $period['to'] : '',
        ];
    }

    /**
     * The optional filters the summary was read with; null when not set.
     *
     * @return array{connectionId:?string,insuranceType:?string,currency:?string}
     */
    public function filters(): array
    {
        $filters = $this->object('filters');
        $read = static fn (string $key): ?string => is_string($filters[$key] ?? null) && $filters[$key] !== '' ? $filters[$key] : null;

        return [
            'connectionId' => $read('connectionId'),
            'insuranceType' => $read('insuranceType'),
            'currency' => $read('currency'),
        ];
    }

    /** @return InsuranceReconciliationGroup[] */
    public function groups(): array
    {
        return $this->rows('groups', static fn (array $row): InsuranceReconciliationGroup => new InsuranceReconciliationGroup($row));
    }

    public function generatedAt(): string
    {
        return $this->str('generatedAt');
    }
}
