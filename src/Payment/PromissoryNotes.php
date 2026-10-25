<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * The notes of a Senetle Ödeme payment: answer of
 * PaymentClient::getPromissoryNotes() and cancelPromissoryNotes().
 */
final class PromissoryNotes
{
    public const DOCUMENT_TYPE_PROMISSORY_NOTE = 'PROMISSORY_NOTE';
    public const DOCUMENT_TYPE_PAYMENT_ORDER = 'PAYMENT_ORDER';

    /** @var array<string,mixed> */
    private array $data;

    /** @var PromissoryNote[] */
    private array $notes;

    /** @param array<string,mixed> $response The full response envelope. */
    public function __construct(array $response)
    {
        $this->data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->notes = array_map(
            static fn ($note): PromissoryNote => new PromissoryNote(is_array($note) ? $note : []),
            array_values(is_array($this->data['notes'] ?? null) ? $this->data['notes'] : [])
        );
    }

    public function paymentId(): string
    {
        return (string) ($this->data['paymentId'] ?? '');
    }

    public function providerCode(): string
    {
        return (string) ($this->data['providerCode'] ?? '');
    }

    /** DOCUMENT_TYPE_PROMISSORY_NOTE or DOCUMENT_TYPE_PAYMENT_ORDER (your provider connection setting). */
    public function documentType(): string
    {
        return (string) ($this->data['documentType'] ?? '');
    }

    /** Serial number of the issue (all notes of the payment). */
    public function rowSerialNumber(): string
    {
        return (string) ($this->data['rowSerialNumber'] ?? '');
    }

    public function currency(): string
    {
        return (string) ($this->data['currency'] ?? '');
    }

    /** Minor units. */
    public function totalAmount(): int
    {
        return (int) ($this->data['totalAmount'] ?? 0);
    }

    /** @return PromissoryNote[] */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Only in the answer of cancelPromissoryNotes(): per note `serialNumber`,
     * `result` (PromissoryNoteCancelResult) and `errorCode`, a Lunixi code
     * (PromissoryNoteCancelResult::ERROR_*) or null.
     *
     * @return list<array{serialNumber:string,result:string,errorCode:?string}>
     */
    public function cancelResults(): array
    {
        $results = [];
        foreach (is_array($this->data['cancelResults'] ?? null) ? $this->data['cancelResults'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $errorCode = $row['errorCode'] ?? null;
            $results[] = [
                'serialNumber' => (string) ($row['serialNumber'] ?? ''),
                'result' => (string) ($row['result'] ?? ''),
                'errorCode' => is_string($errorCode) && $errorCode !== '' ? $errorCode : null,
            ];
        }

        return $results;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
