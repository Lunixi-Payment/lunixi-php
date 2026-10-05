<?php

declare(strict_types=1);

/*
 * A single-use link for a set amount — an invoice or a personal payment request.
 *
 * Amounts are integers in minor units: 150000 is 1,500.00 TRY. Keep the
 * Idempotency-Key you used: sending the same key and body again returns the
 * same link (wasReplayed() === true) instead of creating a second one. The same
 * key with a DIFFERENT body is 409 `payment_link.idempotency.conflict`, so every
 * field — the expiry included — is derived from the invoice, never from the
 * current time: running this again for the same invoice replays it. Set
 * LUNIXI_INVOICE_NO and LUNIXI_INVOICE_DATE (YYYY-MM-DD) together; both default
 * to today's invoice.
 *
 * The API key needs the `payment-link:create` scope. A TEST key creates a TEST
 * link; no money is taken on it.
 */

use Lunixi\Sdk\Payment\CreatePaymentLinkRequest;
use Lunixi\Sdk\Payment\PaymentLinkUsage;

require_once __DIR__ . '/../_common/bootstrap.php';

$invoiceDate = (string) sample_env('LUNIXI_INVOICE_DATE', gmdate('Y-m-d'));
$invoiceNo = sample_env('LUNIXI_INVOICE_NO', 'INV-' . $invoiceDate . '-0001');

// Due 14 days after the invoice date, at 23:59:59 Istanbul time.
$dueAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $invoiceDate . ' 23:59:59', new DateTimeZone('Europe/Istanbul'));
if ($dueAt === false) {
    throw new InvalidArgumentException('LUNIXI_INVOICE_DATE must be YYYY-MM-DD.');
}

$request = CreatePaymentLinkRequest::fixed(PaymentLinkUsage::SINGLE_USE, 150000, 'TRY', 'Danışmanlık bedeli')
    ->withDescription('Fatura ' . $invoiceNo)
    ->withTaxNote('KDV dahil')
    ->withExpiresAt($dueAt->modify('+14 days'))
    ->withReference($invoiceNo)
    ->withRecipient([
        'name' => (string) sample_env('LUNIXI_RECIPIENT_NAME', 'Ada Yilmaz'),
        'email' => (string) sample_env('LUNIXI_RECIPIENT_EMAIL', 'ada@example.com'),
    ])
    ->withMetadata(['invoiceNo' => $invoiceNo]);

$link = sample_client()->paymentLinks()->create($request, 'plink-invoice-' . $invoiceNo);

sample_print([
    'id' => $link->id(),
    'url' => $link->url(),
    'state' => $link->state(),
    'rowVersion' => $link->rowVersion(),
    'replayed' => $link->wasReplayed(),
]);
