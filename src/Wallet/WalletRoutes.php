<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Every merchant wallet route the SDK calls (`/api/v1/wallet/*`): the HTTP
 * method, the path template, whether the gateway requires an
 * `Idempotency-Key`, and the body and query fields the gateway DTO accepts.
 *
 * The sub-clients validate against this table before anything is sent: the
 * gateway runs its ValidationPipe with `forbidNonWhitelisted`, so an unknown
 * body key is a 400 there and a ConfigurationException here. The table mirrors
 * `WALLET_ROUTES` of the Node SDK (`node/src/wallet/wallet-values.js`) route for
 * route and field for field.
 *
 * Source of truth: nest-payment-gateway
 *   apps/api-gateway/src/wallet/wallet-api.controller.ts (routes, idempotency)
 *   apps/api-gateway/src/wallet/dto/wallet.dtos.ts       (fields)
 *
 * Route keys: `method`, `path` (`:name` segments are filled from arguments),
 * `idempotencyKey` (the gateway rejects the call without one), and where they
 * apply `required` / `optional` (body fields), `query` (accepted query
 * parameters) and `binary` (a 2xx answer is an image, not the JSON envelope).
 */
final class WalletRoutes
{
    /** Minor units as a decimal string: `"12550"` is 125.50 TRY. Never a float. */
    public const AMOUNT_PATTERN = '/^\d{1,18}$/';

    /** The gateway trims the header and accepts 1-128 characters. */
    public const IDEMPOTENCY_KEY_MAX = 128;

    /** Body fields that carry a minor-unit amount string. */
    public const AMOUNT_FIELDS = ['amount'];

    /** Body fields that are JSON objects even when empty (`{}`, never `[]`). */
    public const OBJECT_FIELDS = ['metadata'];

    /**
     * @var array<string,array{method:string,path:string,idempotencyKey:bool,required?:string[],optional?:string[],query?:string[],binary?:bool}>
     */
    public const ROUTES = [
        'ping' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/ping',
            'idempotencyKey' => false,
        ],

        // Programs
        'listMyPrograms' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/programs/me',
            'idempotencyKey' => false,
        ],
        'setCollectionAnchor' => [
            'method' => 'PUT',
            'path' => '/api/v1/wallet/programs/:programId/collection-anchor',
            'idempotencyKey' => false,
            'optional' => ['collectionEndUserId', 'reason', 'expectedVersion'],
        ],

        // End users
        'createEndUser' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/end-users',
            'idempotencyKey' => true,
            'required' => ['programId', 'accountType', 'phone'],
            'optional' => ['externalCustomerId', 'kycRef', 'kycLevelCache', 'displayName', 'corporateAccountId', 'defaultCurrency'],
        ],
        'listEndUsers' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/end-users',
            'idempotencyKey' => false,
            'query' => ['programId', 'accountType', 'status', 'corporateAccountId', 'merchantCategoryState', 'limit', 'cursor'],
        ],
        'getEndUserByExternalId' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/end-users/by-external/:externalCustomerId',
            'idempotencyKey' => false,
            'query' => ['programId'],
        ],
        'getEndUser' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/end-users/:endUserId',
            'idempotencyKey' => false,
        ],
        'freezeEndUser' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/end-users/:endUserId/freeze',
            'idempotencyKey' => true,
            'optional' => ['reason'],
        ],
        'getBalances' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/end-users/:endUserId/balances',
            'idempotencyKey' => false,
        ],
        'getKycLevel' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/end-users/:endUserId/kyc-level',
            'idempotencyKey' => false,
        ],
        'updateKycLevel' => [
            'method' => 'PUT',
            'path' => '/api/v1/wallet/end-users/:endUserId/kyc-level',
            'idempotencyKey' => true,
            'required' => ['kycLevel', 'sourceRef'],
            'optional' => ['externalCustomerId', 'programId', 'reason', 'effectiveAt', 'expectedCurrentLevel'],
        ],
        'getLimits' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/end-users/:endUserId/limits',
            'idempotencyKey' => false,
            'query' => ['operationType', 'currency'],
        ],
        'createWallet' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/end-users/:endUserId/wallets',
            'idempotencyKey' => true,
            'optional' => ['name', 'currencies'],
        ],

        // Wallets and accounts
        'createWalletAccount' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/wallets/:walletId/accounts',
            'idempotencyKey' => true,
            'required' => ['currency'],
        ],
        'lookupWallet' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/wallets/lookup',
            'idempotencyKey' => false,
            'optional' => ['programId', 'phone', 'walletNo'],
        ],

        // Deposits (bank transfer in)
        'listDepositInstructions' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/end-users/:endUserId/deposit-instructions',
            'idempotencyKey' => false,
        ],
        'createDepositInstruction' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/end-users/:endUserId/deposit-instructions',
            'idempotencyKey' => false,
            'required' => ['walletAccountId'],
            'optional' => ['currency'],
        ],
        'simulateInboundCredit' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/bank-integrations/inbound-credits',
            'idempotencyKey' => false,
            'required' => ['programId', 'railId', 'amount', 'currency', 'rawReference'],
            'optional' => ['senderName', 'senderIban', 'creditedIban', 'receiptNumber'],
        ],

        // Corporate accounts
        'createCorporateAccount' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/corporate-accounts',
            'idempotencyKey' => true,
            'required' => ['programId', 'code', 'name', 'ownerEndUserId'],
            'optional' => ['fundingCurrency'],
        ],
        'listCorporateAccounts' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/corporate-accounts',
            'idempotencyKey' => false,
            'query' => ['programId', 'status', 'limit', 'cursor'],
        ],
        'importCorporateEmployees' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/corporate-accounts/:corporateAccountId/employees/import',
            'idempotencyKey' => true,
            'required' => ['rows'],
        ],
        'offboardCorporateEmployees' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/corporate-accounts/:corporateAccountId/employees/offboard',
            'idempotencyKey' => true,
            'required' => ['rows'],
            'optional' => ['reason'],
        ],

        // Fees
        'quoteFees' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/fees/quote',
            'idempotencyKey' => false,
            'required' => ['programId', 'operationType', 'currency', 'amount'],
            'optional' => ['at'],
        ],

        // Wallet-to-wallet transfers
        'quoteW2W' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/transfers/w2w/quote',
            'idempotencyKey' => false,
            'required' => ['endUserId', 'sourceWalletAccountId', 'amount', 'currency'],
            'optional' => ['destinationWalletNo', 'destinationPhone', 'paymentPurpose'],
        ],
        'initiateW2W' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/transfers/w2w',
            'idempotencyKey' => true,
            'required' => ['endUserId', 'sourceWalletAccountId', 'amount', 'currency'],
            'optional' => ['destinationWalletNo', 'destinationPhone', 'paymentPurpose', 'scheduleVersionId', 'otpDestination', 'otpChannel', 'locale'],
        ],
        'completeW2W' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/transfers/w2w/:operationId/complete',
            'idempotencyKey' => true,
            'required' => ['endUserId', 'operationId', 'integrityHash', 'nonce'],
            'optional' => ['otpCode', 'trustedActionToken'],
        ],

        // Transfers to an IBAN
        'quoteW2Iban' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/transfers/w2iban/quote',
            'idempotencyKey' => false,
            'required' => ['endUserId', 'sourceWalletAccountId', 'beneficiaryName', 'iban', 'amount', 'currency'],
            'optional' => ['bankCode', 'paymentPurpose'],
        ],
        'initiateW2Iban' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/transfers/w2iban',
            'idempotencyKey' => true,
            'required' => ['endUserId', 'sourceWalletAccountId', 'beneficiaryName', 'iban', 'amount', 'currency'],
            'optional' => ['bankCode', 'paymentPurpose', 'scheduleVersionId', 'otpDestination', 'otpChannel', 'locale'],
        ],
        'completeW2Iban' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/transfers/w2iban/:operationId/complete',
            'idempotencyKey' => true,
            'required' => ['endUserId', 'operationId', 'integrityHash', 'nonce'],
            'optional' => ['otpCode', 'trustedActionToken'],
        ],

        // Withdrawals to the end user's own bank account
        'quoteBankWithdrawal' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/withdrawals/bank/quote',
            'idempotencyKey' => false,
            'required' => ['endUserId', 'sourceWalletAccountId', 'beneficiaryName', 'iban', 'amount', 'currency'],
            'optional' => ['bankCode', 'paymentPurpose'],
        ],
        'initiateBankWithdrawal' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/withdrawals/bank',
            'idempotencyKey' => true,
            'required' => ['endUserId', 'sourceWalletAccountId', 'beneficiaryName', 'iban', 'amount', 'currency'],
            'optional' => ['bankCode', 'paymentPurpose', 'scheduleVersionId', 'otpDestination', 'otpChannel', 'locale'],
        ],
        'completeBankWithdrawal' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/withdrawals/bank/:operationId/complete',
            'idempotencyKey' => true,
            'required' => ['endUserId', 'operationId', 'integrityHash', 'nonce'],
            'optional' => ['otpCode', 'trustedActionToken'],
        ],
        'getWithdrawal' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/withdrawals/:operationId',
            'idempotencyKey' => false,
        ],

        // Operations
        'listOperations' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/operations',
            'idempotencyKey' => false,
            'query' => ['endUserId', 'walletAccountId', 'status', 'operationType', 'destinationWalletAccountId', 'limit', 'cursor'],
        ],
        'getOperation' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/operations/:operationId',
            'idempotencyKey' => false,
        ],
        'getOperationReceipt' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/operations/:operationId/receipt',
            'idempotencyKey' => false,
        ],

        // Top-ups
        'startCardTopup' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/topups/card',
            'idempotencyKey' => true,
            'required' => ['creditWalletAccountId', 'amount', 'currency'],
            'optional' => ['fundingMerchantId', 'savedCardUserKey', 'savedCardToken', 'savedConsumerToken', 'savedPublicCardStorageToken', 'returnUrl'],
        ],
        'getTopup' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/topups/:operationId',
            'idempotencyKey' => false,
        ],
        'refundTopup' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/topups/:operationId/refund',
            'idempotencyKey' => false,
            // The gateway DTO marks approverPrincipal optional, but wallet-service
            // refuses the refund without it (400 WALLET_VALIDATION).
            'required' => ['reason', 'requesterPrincipal', 'approverPrincipal'],
        ],
        'agentTopup' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/agent-topups',
            'idempotencyKey' => true,
            'required' => ['agentId', 'creditWalletAccountId', 'amount', 'currency'],
        ],

        // Payments to the merchant (Wallet2Buy, payment code)
        'requestPayment' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/payments/buy',
            'idempotencyKey' => true,
            'required' => ['amount', 'currency', 'paymentPurpose'],
            'optional' => ['buyerWalletNo', 'buyerPhone', 'orderRef', 'cashierRef', 'otpDestination', 'otpChannel', 'locale'],
        ],
        'approvePayment' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/payments/:operationId/approve',
            'idempotencyKey' => true,
            'required' => ['buyerEndUserId', 'integrityHash', 'nonce'],
            'optional' => ['otpCode', 'trustedActionToken'],
        ],
        'collectByCode' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/collect',
            'idempotencyKey' => true,
            'required' => ['code', 'amount', 'currency'],
            'optional' => ['orderRef', 'paymentPurpose', 'locale'],
        ],
        'getPayment' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/payments/:operationId',
            'idempotencyKey' => false,
        ],
        'getBusinessCommission' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/business-accounts/:businessAccountId/commission',
            'idempotencyKey' => false,
        ],

        // QR codes
        'createQr' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/qr',
            'idempotencyKey' => true,
            'required' => ['qrType', 'currency'],
            'optional' => ['targetEndUserId', 'targetExternalCustomerId', 'programId', 'amount', 'expiresInSeconds', 'metadata'],
        ],
        'listQr' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/qr',
            'idempotencyKey' => false,
            'query' => ['programId', 'qrType', 'status', 'targetEndUserId', 'limit', 'cursor'],
        ],
        'getQr' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/qr/:qrId',
            'idempotencyKey' => false,
        ],
        'getQrImage' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/qr/:qrId/image',
            'idempotencyKey' => false,
            'query' => ['format', 'scale'],
            'binary' => true,
        ],
        'revokeQr' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/qr/:qrId/revoke',
            'idempotencyKey' => false,
        ],
        'parseQr' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/qr/parse',
            'idempotencyKey' => false,
            'required' => ['payload'],
        ],

        // Bulk payouts
        'createBulkPayout' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/bulk-payouts',
            'idempotencyKey' => true,
            'required' => ['programId', 'sourceWalletAccountId', 'currency', 'items'],
            'optional' => ['reason'],
        ],
        'listBulkPayouts' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/bulk-payouts',
            'idempotencyKey' => false,
            'query' => ['programId', 'status', 'limit'],
        ],
        'getBulkPayout' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/bulk-payouts/:batchId',
            'idempotencyKey' => false,
        ],
        'listBulkPayoutItems' => [
            'method' => 'GET',
            'path' => '/api/v1/wallet/bulk-payouts/:batchId/items',
            'idempotencyKey' => false,
            'query' => ['status', 'limit'],
        ],
        'submitBulkPayout' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/bulk-payouts/:batchId/submit',
            'idempotencyKey' => false,
        ],
        'approveBulkPayout' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/bulk-payouts/:batchId/approve',
            'idempotencyKey' => false,
            'required' => ['approve'],
            'optional' => ['reason'],
        ],
        'rejectBulkPayout' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/bulk-payouts/:batchId/reject',
            'idempotencyKey' => false,
            'optional' => ['reason'],
        ],
        'processBulkPayout' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/bulk-payouts/:batchId/process',
            'idempotencyKey' => false,
        ],
        'retryFailedBulkPayout' => [
            'method' => 'POST',
            'path' => '/api/v1/wallet/bulk-payouts/:batchId/retry-failed',
            'idempotencyKey' => false,
        ],
    ];

    /** Fields of the nested rows/items the gateway validates element by element. */
    public const NESTED_FIELDS = [
        'importCorporateEmployees' => ['required' => ['phone'], 'optional' => ['displayName', 'externalCustomerId', 'kycRef']],
        'offboardCorporateEmployees' => ['required' => [], 'optional' => ['endUserId', 'externalCustomerId']],
        'createBulkPayout' => ['required' => ['targetType', 'targetRef', 'amount'], 'optional' => ['beneficiaryName']],
    ];

    /** The body field that holds the nested list of each route in NESTED_FIELDS. */
    public const NESTED_LIST_FIELDS = [
        'importCorporateEmployees' => 'rows',
        'offboardCorporateEmployees' => 'rows',
        'createBulkPayout' => 'items',
    ];

    private function __construct()
    {
    }
}
