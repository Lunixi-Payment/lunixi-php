<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

use Generator;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Outgoing bank transfers — `$lunixi->bankConnect()->transfers()`.
 *
 *   listPurposes            GET  /api/v1/bank-connect/transfer-purposes?connectionId=
 *   create                  POST /api/v1/bank-connect/transfers  (201, Idempotency-Key)
 *   list / iterate          GET  /api/v1/bank-connect/transfers
 *   get                     GET  /api/v1/bank-connect/transfers/{transferId}
 *
 * Available on a connection whose capabilities include OUTGOING_TRANSFER.
 * Every route, reads included, is signed and needs the `bank-connect:transfer`
 * scope; the key must have an IP allowlist and the request must come from a
 * listed IP (403 `bank_connect.auth.ip_allowlist_required` /
 * `bank_connect.auth.ip_not_allowed`).
 *
 * With an API key there is no e-mail code: the key's scope, the signature and
 * the source IP approve the transfer, so create() answers SUBMITTED and the
 * transfer is queued for the bank. Follow it with get() or the
 * `bank_transfer.*` webhooks (BankTransferEvent). The bank chooses the rail;
 * Lunixi does not check the balance — the bank decides.
 */
final class BankTransferClient
{
    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Payment purpose codes the bank accepts on this connection. Pass one as
     * `paymentPurpose` to create(); leave it out for the bank's default.
     *
     * @return BankTransferPurpose[]
     * @throws ApiException
     */
    public function listPurposes(string $connectionId): array
    {
        $data = BankTransferRequests::dataOf(BankTransferRequests::send(
            $this->api,
            'listTransferPurposes',
            [],
            ['connectionId' => BankTransferRequests::requireId($connectionId, 'connectionId')]
        ));
        $rows = isset($data['items']) && is_array($data['items']) ? array_values(array_filter($data['items'], 'is_array')) : [];

        return array_map(static fn (array $row): BankTransferPurpose => new BankTransferPurpose($row), $rows);
    }

    /**
     * Creates an outgoing transfer (HTTP 201).
     *
     * @param array<string,mixed> $request accountId (source account), receiverIban (TR IBAN),
     *   receiverName (2–140 characters), amountMinor (integer > 0, minor units: 150075 = 1,500.75 TRY),
     *   optional currency (TRY), paymentPurpose (a code from listPurposes()), description (≤ 140).
     * @param string|null $idempotencyKey 8–200 printable ASCII; generated when null. The SDK retries a
     *   network error or a 5xx with the same key. Pass your own (e.g. your payout id) to retry safely
     *   across processes: same key + same body → the existing transfer (`replayed()`), same key +
     *   another body → 409 `bank_connect.transfer.idempotency_conflict`.
     * @throws ApiException 412 `bank_connect.transfer.capability_unavailable`, 409
     *   `bank_connect.transfer.account_not_eligible`, 400 `bank_connect.validation.invalid`.
     */
    public function create(array $request, ?string $idempotencyKey = null): BankTransferCreated
    {
        $body = BankTransferRequests::body('createTransfer', $request);
        self::checkTransfer($body);
        $response = BankTransferRequests::send(
            $this->api,
            'createTransfer',
            [],
            [],
            $body,
            BankTransferRequests::idempotencyKey($idempotencyKey)
        );

        return new BankTransferCreated(BankTransferRequests::dataOf($response));
    }

    /**
     * Transfers, newest first.
     *
     * @param array<string,mixed> $filters limit (1–100), cursor, accountId, status (one or a list of
     *   BankTransferStatus), createdFrom (inclusive), createdTo (exclusive) — DateTimeInterface or ISO-8601.
     * @return BankConnectPage<BankTransfer>
     * @throws ApiException
     */
    public function list(array $filters = []): BankConnectPage
    {
        return new BankConnectPage(
            BankTransferRequests::send($this->api, 'listTransfers', [], BankTransferRequests::query('listTransfers', $filters)),
            static fn (array $row): BankTransfer => new BankTransfer($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see list()}.
     * @return Generator<int,BankTransfer>
     */
    public function iterate(array $filters = []): Generator
    {
        do {
            $page = $this->list($filters);
            foreach ($page->items() as $item) {
                yield $item;
            }
            $filters['cursor'] = $page->nextCursor();
        } while ($page->hasMore());
    }

    /** @throws ApiException 404 `bank_connect.transfer.not_found`. */
    public function get(string $transferId): BankTransfer
    {
        $response = BankTransferRequests::send(
            $this->api,
            'getTransfer',
            ['transferId' => BankTransferRequests::requireId($transferId, 'transferId')]
        );

        return new BankTransfer(BankTransferRequests::dataOf($response));
    }

    /** @param array<string,mixed> $body */
    private static function checkTransfer(array &$body): void
    {
        if (strlen($body['receiverIban']) > BankTransferRoutes::IBAN_MAX) {
            throw new ConfigurationException("'receiverIban' must be at most " . BankTransferRoutes::IBAN_MAX . ' characters.');
        }
        $nameLength = BankTransferRequests::chars($body['receiverName']);
        if ($nameLength < BankTransferRoutes::RECEIVER_NAME_MIN || $nameLength > BankTransferRoutes::RECEIVER_NAME_MAX) {
            throw new ConfigurationException(sprintf(
                "'receiverName' must be %d-%d characters.",
                BankTransferRoutes::RECEIVER_NAME_MIN,
                BankTransferRoutes::RECEIVER_NAME_MAX
            ));
        }
        if (isset($body['currency'])) {
            $currency = is_string($body['currency']) ? strtoupper(trim($body['currency'])) : '';
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new ConfigurationException("'currency' must be an ISO 4217 code such as TRY.");
            }
            $body['currency'] = $currency;
        }
        if (isset($body['paymentPurpose'])
            && (!is_string($body['paymentPurpose']) || preg_match(BankTransferRoutes::PAYMENT_PURPOSE_PATTERN, $body['paymentPurpose']) !== 1)) {
            throw new ConfigurationException("'paymentPurpose' must be a code from listPurposes() (letters, digits, underscore; at most 64).");
        }
        if (isset($body['description'])) {
            BankTransferRequests::description($body['description']);
        }
    }
}
