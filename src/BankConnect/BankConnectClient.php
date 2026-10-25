<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Generator;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Bank accounts — read your connected bank accounts, balances and
 * transactions (`/api/v1/bank-connect`, product BANK_CONNECT).
 *
 *   listBanks / iterateBanks                GET  /api/v1/bank-connect/banks
 *   listConnections / iterateConnections    GET  /api/v1/bank-connect/connections
 *   getConnection                           GET  /api/v1/bank-connect/connections/{connectionId}
 *   listAccounts / iterateAccounts          GET  /api/v1/bank-connect/accounts
 *   getAccount                              GET  /api/v1/bank-connect/accounts/{accountId}
 *   listBalances / iterateBalances          GET  /api/v1/bank-connect/balances
 *   listTransactions / iterateTransactions  GET  /api/v1/bank-connect/transactions
 *   getTransaction                          GET  /api/v1/bank-connect/transactions/{transactionId}
 *   requestSync                             POST /api/v1/bank-connect/accounts/{accountId}/sync  (202)
 *   transfers()                             BankTransferClient — outgoing transfers
 *   fx()                                    BankFxClient — currency exchange between own accounts
 *
 * These routes accept the API key's per-request Ed25519 signature only, so
 * every call is sent step-up (signed), reads included; an unsigned request is
 * 401 `AUTH_HEADERS_MISSING`. Scopes: `bank-connect:view` for banks,
 * connections, accounts and requestSync; `bank-connect:transactions:view` for
 * balances and transactions (403 `API_INSUFFICIENT_SCOPE` otherwise). The
 * key also decides the environment: a TEST key reads only test-environment
 * accounts, a LIVE key only live ones.
 *
 * Connecting or disconnecting a bank and entering bank credentials happen in
 * the Lunixi dashboard only. Transfers and currency exchange (transfers(),
 * fx()) need the `bank-connect:transfer` scope, a key with an IP allowlist and
 * a connection whose capabilities include OUTGOING_TRANSFER / FX_EXCHANGE. Another organisation's or
 * the other environment's record is 404, the same as a record that does not
 * exist.
 *
 * Filters are checked before anything is sent, because the gateway rejects an
 * unknown query field with 400: an unknown filter, a value outside its set or
 * a malformed amount or date range is a ConfigurationException.
 */
final class BankConnectClient
{
    /** Largest page (`limit` 1–100; the gateway's default is 25). */
    public const MAX_LIMIT = 100;

    /** Most account ids one `accountId` filter may carry. */
    public const MAX_ACCOUNT_FILTER = 100;

    /** Widest `bookedFrom` … `bookedTo` window of one transaction request, in days. */
    public const BOOKED_RANGE_MAX_DAYS = 92;

    /** The rate-limit error code of requestSync() (HTTP 429). */
    public const RATE_LIMITED = 'RATE_LIMITED';

    private const BASE = '/api/v1/bank-connect';

    /** `Number.MAX_SAFE_INTEGER`: the gateway's ceiling for an amount filter. */
    private const MAX_AMOUNT_MINOR = 9007199254740991;

    private const DAY_MS = 86400000;

    private const CURSOR_MAX_LENGTH = 2048;

    private const BANK_FILTERS = ['limit', 'cursor'];
    private const CONNECTION_FILTERS = ['limit', 'cursor', 'status', 'bankId'];
    private const ACCOUNT_FILTERS = ['limit', 'cursor', 'connectionId', 'bankId', 'currency', 'status'];
    private const BALANCE_FILTERS = ['limit', 'cursor', 'accountId', 'currency'];
    private const TRANSACTION_FILTERS = [
        'limit', 'cursor', 'accountId', 'bookedFrom', 'bookedTo', 'direction',
        'minAmountMinor', 'maxAmountMinor', 'canonicalType',
    ];

    private ApiClient $api;

    private BankTransferClient $transfers;

    private BankFxClient $fx;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
        $this->transfers = new BankTransferClient($api);
        $this->fx = new BankFxClient($api);
    }

    /** Outgoing transfers from a connected account (`bank-connect:transfer`). */
    public function transfers(): BankTransferClient
    {
        return $this->transfers;
    }

    /** Currency exchange between two of your accounts on one connection (`bank-connect:transfer`). */
    public function fx(): BankFxClient
    {
        return $this->fx;
    }

    /**
     * The bank catalogue, with whether each bank can be connected and what is
     * read from it.
     *
     * @param array<string,mixed> $filters limit (1–100), cursor.
     * @return BankConnectPage<BankConnectBank>
     * @throws ApiException
     */
    public function listBanks(array $filters = []): BankConnectPage
    {
        return new BankConnectPage(
            $this->read('/banks', self::query($filters, self::BANK_FILTERS)),
            static fn (array $row): BankConnectBank => new BankConnectBank($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listBanks()}.
     * @return Generator<int,BankConnectBank>
     */
    public function iterateBanks(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): BankConnectPage => $this->listBanks($page), $filters);
    }

    /**
     * Your bank connections, newest first.
     *
     * @param array<string,mixed> $filters limit, cursor, status (one or a list of
     *   BankConnectionStatus), bankId.
     * @return BankConnectPage<BankConnectConnection>
     * @throws ApiException
     */
    public function listConnections(array $filters = []): BankConnectPage
    {
        return new BankConnectPage(
            $this->read('/connections', self::query($filters, self::CONNECTION_FILTERS)),
            static fn (array $row): BankConnectConnection => new BankConnectConnection($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listConnections()}.
     * @return Generator<int,BankConnectConnection>
     */
    public function iterateConnections(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): BankConnectPage => $this->listConnections($page), $filters);
    }

    /** @throws ApiException 404 `bank_connect.connection.not_found`. */
    public function getConnection(string $connectionId): BankConnectConnection
    {
        $response = $this->read('/connections/' . rawurlencode(self::requireId($connectionId, 'connectionId')));

        return new BankConnectConnection(self::dataOf($response));
    }

    /**
     * Your connected accounts, newest first, each with its data freshness.
     *
     * @param array<string,mixed> $filters limit, cursor, connectionId, bankId,
     *   currency (ISO 4217), status (BankAccountStatus).
     * @return BankConnectPage<BankConnectAccount>
     * @throws ApiException
     */
    public function listAccounts(array $filters = []): BankConnectPage
    {
        return new BankConnectPage(
            $this->read('/accounts', self::query($filters, self::ACCOUNT_FILTERS)),
            static fn (array $row): BankConnectAccount => new BankConnectAccount($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listAccounts()}.
     * @return Generator<int,BankConnectAccount>
     */
    public function iterateAccounts(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): BankConnectPage => $this->listAccounts($page), $filters);
    }

    /** @throws ApiException 404 `bank_connect.account.not_found`. */
    public function getAccount(string $accountId): BankConnectAccount
    {
        return new BankConnectAccount(self::dataOf($this->read(self::accountPath($accountId))));
    }

    /**
     * Asks for a fresh read of the account from the bank (HTTP 202).
     *
     * It never reads earlier than the bank's minimum interval: when the
     * interval has not passed the answer is SCHEDULED with `scheduledAt`, and
     * a request while one is already waiting joins it. Accounts that share a
     * bank login are read together. Nothing is written, so no
     * Idempotency-Key is needed. New transactions arrive by the
     * `bank_account.transaction.received` webhook.
     *
     * @throws ApiException 409 `bank_connect.connection.not_active` (the connection is not
     *   ACTIVE or DEGRADED); 429 `RATE_LIMITED` when the key sent too many sync requests
     *   in the last minute — wait {@see retryAfterSeconds()}.
     */
    public function requestSync(string $accountId): BankConnectSyncRequest
    {
        $response = $this->api->request('POST', self::BASE . self::accountPath($accountId) . '/sync', null, ['stepUp' => true]);

        return new BankConnectSyncRequest(self::dataOf($response));
    }

    /**
     * The latest balance of each account (scope `bank-connect:transactions:view`).
     * Without `accountId`, every active account of the key's environment.
     *
     * @param array<string,mixed> $filters limit, cursor, accountId (one id or a list of
     *   up to 100), currency.
     * @return BankConnectPage<BankConnectBalance>
     * @throws ApiException
     */
    public function listBalances(array $filters = []): BankConnectPage
    {
        return new BankConnectPage(
            $this->read('/balances', self::query($filters, self::BALANCE_FILTERS)),
            static fn (array $row): BankConnectBalance => new BankConnectBalance($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listBalances()}.
     * @return Generator<int,BankConnectBalance>
     */
    public function iterateBalances(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): BankConnectPage => $this->listBalances($page), $filters);
    }

    /**
     * Transactions, newest recorded first (scope `bank-connect:transactions:view`).
     *
     * The order is `receivedAt` (when Lunixi recorded the transaction), which
     * never changes, so paging forward never skips a transaction that arrives
     * late with an older bank date. `bookedFrom` (inclusive) / `bookedTo`
     * (exclusive) filter on the bank date: DateTimeInterface or an ISO-8601
     * string with a time zone; at most 92 days apart, and `bookedTo` needs
     * `bookedFrom` (`bookedFrom` alone runs up to now). Amount filters are
     * inclusive integers in minor units.
     *
     * @param array<string,mixed> $filters limit, cursor, accountId (one id or a list of up to 100),
     *   bookedFrom, bookedTo, direction (BankTransactionDirection), minAmountMinor, maxAmountMinor,
     *   canonicalType (one or a list of BankTransactionType).
     * @return BankConnectPage<BankConnectTransaction>
     * @throws ApiException 400 `bank_connect.validation.invalid` (`details.reason`
     *   `range_too_large`, `booked_from_required`, `after_booked_to`, `min_greater_than_max`).
     */
    public function listTransactions(array $filters = []): BankConnectPage
    {
        $query = self::query($filters, self::TRANSACTION_FILTERS);
        self::assertBookedRange($filters);
        self::assertAmountRange($query);

        return new BankConnectPage(
            $this->read('/transactions', $query),
            static fn (array $row): BankConnectTransaction => new BankConnectTransaction($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listTransactions()}.
     * @return Generator<int,BankConnectTransaction>
     */
    public function iterateTransactions(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): BankConnectPage => $this->listTransactions($page), $filters);
    }

    /**
     * One transaction with its detail fields (`balanceAfterMinor`, `documentNo`, `bank`).
     *
     * @throws ApiException 404 `bank_connect.transaction.not_found`.
     */
    public function getTransaction(string $transactionId): BankConnectTransaction
    {
        $response = $this->read('/transactions/' . rawurlencode(self::requireId($transactionId, 'transactionId')));

        return new BankConnectTransaction(self::dataOf($response));
    }

    /**
     * Seconds to wait after a 429 `RATE_LIMITED` from requestSync(), from the
     * error body (`details.retryAfterSeconds`; the HTTP `Retry-After` header
     * carries the same value). Null for any other error.
     */
    public static function retryAfterSeconds(ApiException $error): ?int
    {
        if ($error->getStatusCode() !== 429 || $error->getErrorCode() !== self::RATE_LIMITED) {
            return null;
        }
        $details = ($error->getResponseBody() ?? [])['details'] ?? null;
        $seconds = is_array($details) ? ($details['retryAfterSeconds'] ?? null) : null;

        return is_int($seconds) && $seconds >= 0 ? $seconds : null;
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function read(string $path, array $query = []): array
    {
        $options = ['stepUp' => true];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->api->request('GET', self::BASE . $path, null, $options);
    }

    /**
     * @param callable(array<string,mixed>):BankConnectPage<mixed> $fetchPage
     * @param array<string,mixed> $filters
     * @return Generator<int,mixed>
     */
    private static function paginate(callable $fetchPage, array $filters): Generator
    {
        do {
            $page = $fetchPage($filters);
            foreach ($page->items() as $item) {
                yield $item;
            }
            $filters['cursor'] = $page->nextCursor();
        } while ($page->hasMore());
    }

    /**
     * @param array<string,mixed> $filters
     * @param string[] $allowed
     * @return array<string,scalar>
     */
    private static function query(array $filters, array $allowed): array
    {
        $unknown = array_diff(array_map('strval', array_keys($filters)), $allowed);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'Unknown bank-connect filter(s): %s. Allowed: %s. The gateway rejects unknown query fields with 400.',
                implode(', ', $unknown),
                implode(', ', $allowed)
            ));
        }

        $query = [];
        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $query[$key] = self::filterValue($key, $value, $allowed);
        }

        return $query;
    }

    /**
     * @param mixed $value
     * @param string[] $allowed The filters of the route (`status` differs per route).
     * @return scalar
     */
    private static function filterValue(string $key, $value, array $allowed)
    {
        switch ($key) {
            case 'limit':
                if (!is_int($value) || $value < 1 || $value > self::MAX_LIMIT) {
                    throw new ConfigurationException("'limit' must be an integer from 1 to " . self::MAX_LIMIT . '.');
                }
                return $value;
            case 'cursor':
                if (!is_string($value) || strlen($value) > self::CURSOR_MAX_LENGTH) {
                    throw new ConfigurationException("'cursor' must be the nextCursor of the previous page, passed back unchanged.");
                }
                return $value;
            case 'status':
                return in_array('connectionId', $allowed, true)
                    ? self::oneOf($key, $value, BankAccountStatus::ALL)
                    : self::listOf($key, $value, BankConnectionStatus::ALL);
            case 'direction':
                return self::oneOf($key, $value, BankTransactionDirection::ALL);
            case 'canonicalType':
                return self::listOf($key, $value, BankTransactionType::ALL);
            case 'accountId':
                return self::idList($value);
            case 'bankId':
            case 'connectionId':
                return self::requireId(is_string($value) ? $value : '', $key);
            case 'currency':
                $currency = is_string($value) ? strtoupper(trim($value)) : '';
                if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                    throw new ConfigurationException("'currency' must be an ISO 4217 code such as TRY.");
                }
                return $currency;
            case 'bookedFrom':
            case 'bookedTo':
                return self::isoTime($key, $value);
            case 'minAmountMinor':
            case 'maxAmountMinor':
                if (!is_int($value) || $value < 0 || $value > self::MAX_AMOUNT_MINOR) {
                    throw new ConfigurationException("'{$key}' must be a non-negative integer in minor units (12550 = 125.50 TRY).");
                }
                return $value;
        }

        throw new ConfigurationException("Unknown bank-connect filter '{$key}'.");
    }

    /**
     * @param mixed $value
     * @param string[] $allowed
     */
    private static function oneOf(string $key, $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ConfigurationException("'{$key}' must be one of " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    /**
     * @param mixed $value One value or a list.
     * @param string[] $allowed
     */
    private static function listOf(string $key, $value, array $allowed): string
    {
        $values = is_array($value) ? array_values($value) : [$value];
        foreach ($values as $entry) {
            self::oneOf($key, $entry, $allowed);
        }

        return implode(',', $values);
    }

    /** @param mixed $value One account id or a list. */
    private static function idList($value): string
    {
        $ids = is_array($value) ? array_values($value) : [$value];
        if (count($ids) > self::MAX_ACCOUNT_FILTER) {
            throw new ConfigurationException("'accountId' takes at most " . self::MAX_ACCOUNT_FILTER . ' account ids.');
        }
        foreach ($ids as $index => $id) {
            if (!is_string($id) || trim($id) === '' || strpos($id, ',') !== false) {
                throw new ConfigurationException("'accountId' must be account ids (strings without commas).");
            }
            $ids[$index] = trim($id);
        }

        return implode(',', $ids);
    }

    /** @param mixed $value DateTimeInterface or an ISO-8601 string with a time zone. */
    private static function isoTime(string $key, $value): string
    {
        if ($value instanceof DateTimeInterface) {
            $utc = DateTimeImmutable::createFromFormat('U.u', $value->format('U.u'), new DateTimeZone('UTC'));
            if ($utc === false) {
                throw new ConfigurationException("'{$key}' is not a valid time.");
            }
            return $utc->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        throw new ConfigurationException("'{$key}' must be a DateTimeInterface or an ISO-8601 string with a time zone.");
    }

    /**
     * The gateway's window rules, checked locally when both ends are readable:
     * `bookedTo` needs `bookedFrom`, `bookedFrom` <= `bookedTo`, at most 92 days.
     *
     * @param array<string,mixed> $filters
     */
    private static function assertBookedRange(array $filters): void
    {
        $from = $filters['bookedFrom'] ?? null;
        $to = $filters['bookedTo'] ?? null;
        if (($to !== null && $to !== '') && ($from === null || $from === '')) {
            throw new ConfigurationException("'bookedTo' needs 'bookedFrom': a window without a start is unbounded.");
        }
        $fromMs = self::epochMs($from);
        $toMs = self::epochMs($to);
        if ($fromMs === null || $toMs === null) {
            return;
        }
        if ($fromMs > $toMs) {
            throw new ConfigurationException("'bookedFrom' must not be after 'bookedTo'.");
        }
        if ($toMs - $fromMs > self::BOOKED_RANGE_MAX_DAYS * self::DAY_MS) {
            throw new ConfigurationException(
                "'bookedFrom' and 'bookedTo' may be at most " . self::BOOKED_RANGE_MAX_DAYS . ' days apart. Page through narrower windows.'
            );
        }
    }

    /** @param array<string,scalar> $query */
    private static function assertAmountRange(array $query): void
    {
        if (isset($query['minAmountMinor'], $query['maxAmountMinor']) && $query['minAmountMinor'] > $query['maxAmountMinor']) {
            throw new ConfigurationException("'minAmountMinor' must not be greater than 'maxAmountMinor'.");
        }
    }

    /** @param mixed $value */
    private static function epochMs($value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return (int) $value->format('Uv');
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (int) (new DateTimeImmutable(trim($value)))->format('Uv');
        } catch (Exception $unreadable) {
            return null;
        }
    }

    /** Path below BASE. */
    private static function accountPath(string $accountId): string
    {
        return '/accounts/' . rawurlencode(self::requireId($accountId, 'accountId'));
    }

    private static function requireId(string $value, string $field): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new ConfigurationException("'{$field}' is required.");
        }

        return $trimmed;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }
}
