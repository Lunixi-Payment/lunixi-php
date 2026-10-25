<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * A bank account transaction: a row of listTransactions() or, with the detail
 * fields (`balanceAfterMinor`, `documentNo`, `bank`), getTransaction().
 *
 * `amountMinor` is always positive; `direction` says whether money came in
 * (CREDIT) or went out (DEBIT). `description` is the bank's text as received,
 * so a payment reference written by the payer can be matched against it. The
 * counterparty's name and IBAN are masked; their tax or identity number is
 * never returned.
 */
final class BankConnectTransaction extends BankConnectResource
{
    public function transactionId(): string
    {
        return $this->str('transactionId');
    }

    public function accountId(): string
    {
        return $this->str('accountId');
    }

    /** One of the BankTransactionDirection constants. */
    public function direction(): string
    {
        return $this->str('direction');
    }

    public function isCredit(): bool
    {
        return $this->direction() === BankTransactionDirection::CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->direction() === BankTransactionDirection::DEBIT;
    }

    public function amountMinor(): int
    {
        return $this->int('amountMinor');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    /** The transaction date the bank reported. */
    public function bookedAt(): ?string
    {
        return $this->strOrNull('bookedAt');
    }

    public function valueAt(): ?string
    {
        return $this->strOrNull('valueAt');
    }

    /** One of the BankTransactionType constants. */
    public function canonicalType(): string
    {
        return $this->str('canonicalType');
    }

    public function description(): ?string
    {
        return $this->strOrNull('description');
    }

    public function counterpartyNameMasked(): ?string
    {
        return $this->strOrNull('counterpartyNameMasked');
    }

    public function counterpartyIbanMasked(): ?string
    {
        return $this->strOrNull('counterpartyIbanMasked');
    }

    public function receiptNo(): ?string
    {
        return $this->strOrNull('receiptNo');
    }

    /** When Lunixi recorded the transaction. Lists are ordered by it, newest first. */
    public function receivedAt(): ?string
    {
        return $this->strOrNull('receivedAt');
    }

    /** True only when this row reverses an earlier transaction (the field is left out otherwise). */
    public function isReversal(): bool
    {
        return ($this->data['isReversal'] ?? false) === true;
    }

    /** The transaction this row reverses, when the bank links it. */
    public function reversalOfTransactionId(): ?string
    {
        return $this->strOrNull('reversalOfTransactionId');
    }

    /** EFT code of the counterparty's bank, when the bank reports it. */
    public function counterpartyBankCode(): ?string
    {
        return $this->strOrNull('counterpartyBankCode');
    }

    public function counterpartyBankName(): ?string
    {
        return $this->strOrNull('counterpartyBankName');
    }

    /** Detail only: the account balance after this transaction, when the bank reports it. */
    public function balanceAfterMinor(): ?int
    {
        return $this->intOrNull('balanceAfterMinor');
    }

    /** Detail only. */
    public function documentNo(): ?string
    {
        return $this->strOrNull('documentNo');
    }

    /** Detail only; null on a list row. */
    public function bank(): ?BankConnectBankRef
    {
        $bank = $this->object('bank');
        return $bank === [] ? null : new BankConnectBankRef($bank);
    }
}
