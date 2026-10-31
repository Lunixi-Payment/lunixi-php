<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

/**
 * Native adapters implement these operations on the SAME database connection
 * used for order changes. transaction must durably serialize one order across
 * processes, rollback on exceptions and commit before returning. claimEvent is
 * atomic, transaction-bound, and globally unique per configured merchant/event.
 * No in-memory cache or PHP session is a substitute for this ledger.
 */
interface LedgerStore
{
    /** @return mixed */
    public function transaction(string $orderKey, callable $operation);
    /** @return array<string,mixed>|null */
    public function load(string $orderKey): ?array;
    /** @param array<string,mixed> $record */
    public function save(string $orderKey, array $record): void;
    /** Same event ID with a different SHA-256 payload hash must throw, not silently acknowledge. */
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool;
}
