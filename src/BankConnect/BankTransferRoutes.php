<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Transfer and exchange routes: method, path, body fields, query fields and
 * the Idempotency-Key rule. Same table as the Node SDK's
 * `BANK_TRANSFER_ROUTES` (node/src/bank-connect/bank-connect-values.js), the
 * single source; a test keeps the two equal. Gateway source:
 * bank-connect/transfers/bank-connect-transfer-api.controller.ts and
 * dtos/bank-connect-transfer.dto.ts (forbidNonWhitelisted: any other field is
 * a 400).
 */
final class BankTransferRoutes
{
    private const BASE = '/api/v1/bank-connect';

    public const ROUTES = [
        'listTransferPurposes' => [
            'method' => 'GET', 'path' => self::BASE . '/transfer-purposes', 'idempotencyKey' => false,
            'query' => ['connectionId'],
        ],
        'createTransfer' => [
            'method' => 'POST', 'path' => self::BASE . '/transfers', 'idempotencyKey' => true,
            'required' => ['accountId', 'receiverIban', 'receiverName', 'amountMinor'],
            'optional' => ['currency', 'paymentPurpose', 'description'],
        ],
        'listTransfers' => [
            'method' => 'GET', 'path' => self::BASE . '/transfers', 'idempotencyKey' => false,
            'query' => ['limit', 'cursor', 'accountId', 'status', 'createdFrom', 'createdTo'],
        ],
        'getTransfer' => ['method' => 'GET', 'path' => self::BASE . '/transfers/:transferId', 'idempotencyKey' => false],
        'listFxRates' => [
            'method' => 'GET', 'path' => self::BASE . '/fx/rates', 'idempotencyKey' => false,
            'query' => ['connectionId'],
        ],
        'createFxQuote' => [
            'method' => 'POST', 'path' => self::BASE . '/fx/quotes', 'idempotencyKey' => false,
            'required' => ['sourceAccountId', 'targetAccountId'],
            'optional' => ['sourceAmountMinor', 'targetAmountMinor'],
        ],
        'createFxOrder' => [
            'method' => 'POST', 'path' => self::BASE . '/fx/orders', 'idempotencyKey' => true,
            'required' => ['quoteId'],
            'optional' => ['description'],
        ],
        'listFxOrders' => [
            'method' => 'GET', 'path' => self::BASE . '/fx/orders', 'idempotencyKey' => false,
            'query' => ['limit', 'cursor', 'status'],
        ],
        'getFxOrder' => ['method' => 'GET', 'path' => self::BASE . '/fx/orders/:orderId', 'idempotencyKey' => false],
    ];

    /** `Idempotency-Key`: 8–200 printable ASCII characters. */
    public const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{8,200}$/';

    public const DESCRIPTION_MAX = 140;
    public const RECEIVER_NAME_MIN = 2;
    public const RECEIVER_NAME_MAX = 140;
    public const IBAN_MAX = 42;
    public const PAYMENT_PURPOSE_PATTERN = '/^[A-Za-z0-9_]{1,64}$/';

    private function __construct()
    {
    }
}
