<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\HttpResponse;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\MethodInputField;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\PromissoryNoteCancelResult;
use Lunixi\Sdk\Payment\PromissoryNoteDocument;
use Lunixi\Sdk\Payment\PromissoryNoteEvent;
use Lunixi\Sdk\Payment\PromissoryNoteEvents;
use Lunixi\Sdk\Payment\PromissoryNotePlan;
use Lunixi\Sdk\Payment\PromissoryNoteStatus;
use Lunixi\Sdk\Reference\ReferenceClient;
use Lunixi\Sdk\Tests\Support\FakeHttpClient;
use Lunixi\Sdk\Webhook\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Senetle Ödeme (paymentMethod PROMISSORY_NOTE): the `promissoryNote` block of
 * POST /payments/direct/payment, `promissoryNote.schedule` of the intent, the three
 * promissory-notes endpoints and the public address reference. Bodies follow the
 * gateway's DirectMethodPaymentDto / CreatePaymentIntentDto / CancelPromissoryNotesDto.
 * All values are synthetic.
 */
final class PromissoryNoteTest extends TestCase
{
    private const PAYMENT_ID = '7a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    private const SCHEDULE = [
        ['amount' => 100000, 'dueDate' => '2030-01-15'],
        ['amount' => 200000, 'dueDate' => '2030-02-15'],
    ];

    /** @return array<string,mixed> Provider-declared debtor fields; test values only. */
    private static function debtor(): array
    {
        return [
            'debtorType' => 'INDIVIDUAL',
            'name' => 'Deneme',
            'surname' => 'Borclu',
            'birthDate' => '1990-01-01',
            'address' => ['provinceCode' => '06', 'districtCode' => '1001', 'neighborhoodCode' => '2001', 'line' => 'Ornek Sokak No 1'],
            'guarantors' => [['name' => 'Deneme', 'surname' => 'Kefil', 'birthDate' => '1985-05-05', 'gsmNumber' => '+905000000001']],
        ];
    }

    /** @return array{0:ApiClient,1:FakeHttpClient} */
    private function api(): array
    {
        $keys = Ed25519Signer::generateKeyPair();
        $config = new Configuration([
            'baseUrl' => 'https://gw.example.com',
            'keyId' => 'kid_1',
            'privateKey' => $keys['privateKey'],
            'maxRetries' => 0,
        ]);
        $signer = new Ed25519Signer($keys['privateKey']);
        $http = new FakeHttpClient();
        $store = new InMemoryTokenStore();
        $store->set($config->tokenCacheKey(), 'bearer_x', 3600);
        $tokens = new TokenManager($config, $signer, $http, $store);

        return [new ApiClient($config, $signer, $tokens, $http), $http];
    }

    private function promissoryRequest(): DirectMethodPaymentRequest
    {
        return (new DirectMethodPaymentRequest(300000, 'TRY', 'ORD-PN-1'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, self::debtor())
            ->withPromissoryNoteSchedule(self::SCHEDULE);
    }

    /** @return array<string,mixed> */
    private static function notesEnvelope(array $extra = []): array
    {
        return [
            'status' => 'success',
            'code' => 'SUCCESS',
            'data' => array_merge([
                'paymentId' => self::PAYMENT_ID,
                'providerCode' => 'TEST_PROVIDER',
                'documentType' => 'PROMISSORY_NOTE',
                'rowSerialNumber' => 'ROW-0001',
                'currency' => 'TRY',
                'totalAmount' => 300000,
                'notes' => [
                    ['serialNumber' => 'NOTE-0001', 'sequence' => 1, 'amount' => 100000, 'dueDate' => '2030-01-15', 'status' => 'ISSUED', 'paidAt' => null, 'protestedAt' => null, 'cancelledAt' => null],
                    ['serialNumber' => 'NOTE-0002', 'sequence' => 2, 'amount' => 200000, 'dueDate' => '2030-02-15', 'status' => 'PAID', 'paidAt' => '2030-02-16T09:00:00.000Z', 'protestedAt' => null, 'cancelledAt' => null],
                ],
            ], $extra),
        ];
    }

    /** @return array<string,mixed> */
    private static function body(FakeHttpClient $http): array
    {
        return json_decode((string) $http->lastCall()['body'], true);
    }

    // --- Value sets ----------------------------------------------------------------------

    public function testValueSetsMatchTheGatewayContract(): void
    {
        $this->assertSame(24, PromissoryNotePlan::MAX_ITEMS);
        $this->assertSame(['ISSUED', 'PAID', 'PROTESTED', 'CANCELLED'], PromissoryNoteStatus::ALL);
        $this->assertSame(['CANCELLED', 'ALREADY_CLOSED', 'NOT_CANCELLABLE', 'FAILED'], PromissoryNoteCancelResult::ALL);
        $this->assertSame([
            'payment.promissory_note.not_cancellable',
            'payment.promissory_note.provider_unavailable',
            'payment.promissory_note.cancel_rejected',
        ], PromissoryNoteCancelResult::ERROR_CODES);
        $this->assertSame(['tr-TR', 'en-GB'], PromissoryNoteDocument::LOCALES);
        $this->assertSame([
            'payment.promissory_note.paid',
            'payment.promissory_note.protested',
            'payment.promissory_note.cancelled',
        ], PromissoryNoteEvents::ALL);
        $this->assertSame(['VKN', 'ADDRESS', 'GROUP'], [MethodInputField::TYPE_VKN, MethodInputField::TYPE_ADDRESS, MethodInputField::TYPE_GROUP]);
        $this->assertContains('promissoryNote', PaymentMethod::METHOD_BLOCK_FIELDS);
        $this->assertContains('promissoryNote', PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS);
    }

    // --- DirectMethodPaymentRequest ------------------------------------------------------

    public function testAScheduleGoesIntoThePromissoryNoteBlockNextToTheProviderFields(): void
    {
        $body = $this->promissoryRequest()->toArray();

        $this->assertSame([
            'paymentMethod' => 'PROMISSORY_NOTE',
            'amount' => 300000,
            'currency' => 'TRY',
            'orderId' => 'ORD-PN-1',
            'promissoryNote' => self::debtor() + ['schedule' => self::SCHEDULE],
        ], $body);
        foreach (array_keys($body) as $key) {
            $this->assertContains($key, PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS);
        }
    }

    public function testANoteCountIsSentAsAStringAfterTheProviderCode(): void
    {
        $body = (new DirectMethodPaymentRequest(300000, 'TRY', 'ORD-PN-2'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, 'TEST_PROVIDER', ['debtorType' => 'INDIVIDUAL'])
            ->withPromissoryNoteCount(3)
            ->toArray();

        $this->assertSame(['providerCode' => 'TEST_PROVIDER', 'debtorType' => 'INDIVIDUAL', 'noteCount' => '3'], $body['promissoryNote']);

        $fromFields = (new DirectMethodPaymentRequest(300000, 'TRY', 'ORD-PN-3'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, ['noteCount' => 2])
            ->toArray();
        $this->assertSame(['noteCount' => '2'], $fromFields['promissoryNote']);
    }

    public function testThePlanIsRequired(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('needs the plan');

        (new DirectMethodPaymentRequest(300000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, self::debtor())
            ->toArray();
    }

    public function testThePlanIsGivenOnce(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('given twice');

        (new DirectMethodPaymentRequest(300000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, ['noteCount' => '3'])
            ->withPromissoryNoteSchedule(self::SCHEDULE)
            ->toArray();
    }

    public function testTheScheduleIsNotAProviderField(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('withPromissoryNoteSchedule()');

        (new DirectMethodPaymentRequest(300000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, ['schedule' => self::SCHEDULE]);
    }

    public function testAPlanNeedsThePromissoryNoteMethod(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('only be sent with paymentMethod PROMISSORY_NOTE');

        (new DirectMethodPaymentRequest(5000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::MOBILE_BILLING, null, [])
            ->withPromissoryNoteCount(2)
            ->toArray();
    }

    public function testTheLaterPlanReplacesTheEarlierOne(): void
    {
        $body = (new DirectMethodPaymentRequest(300000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, [])
            ->withPromissoryNoteCount(2)
            ->withPromissoryNoteSchedule(self::SCHEDULE)
            ->toArray();

        $this->assertSame(['schedule' => self::SCHEDULE], $body['promissoryNote']);
    }

    /** @return array<string,array{0:array<mixed>}> */
    public static function malformedSchedules(): array
    {
        return [
            'empty' => [[]],
            'not a list' => [['first' => ['amount' => 300000, 'dueDate' => '2030-01-15']]],
            'row is a list' => [[[300000, '2030-01-15']]],
            'no due date' => [[['amount' => 300000]]],
            'day-month-year' => [[['amount' => 300000, 'dueDate' => '15.01.2030']]],
            'not a calendar day' => [[['amount' => 300000, 'dueDate' => '2030-02-30']]],
            'decimal amount' => [[['amount' => 3000.5, 'dueDate' => '2030-01-15']]],
            'string amount' => [[['amount' => '300000', 'dueDate' => '2030-01-15']]],
            'zero amount' => [[['amount' => 0, 'dueDate' => '2030-01-15']]],
            'extra key' => [[['amount' => 300000, 'dueDate' => '2030-01-15', 'currency' => 'TRY']]],
            'descending' => [[['amount' => 100000, 'dueDate' => '2030-02-15'], ['amount' => 200000, 'dueDate' => '2030-01-15']]],
            'too many rows' => [array_fill(0, 25, ['amount' => 12000, 'dueDate' => '2030-01-15'])],
        ];
    }

    /** @param array<mixed> $schedule */
    #[DataProvider('malformedSchedules')]
    public function testScheduleRowsAreCheckedBeforeSending(array $schedule): void
    {
        $this->expectException(ConfigurationException::class);

        (new DirectMethodPaymentRequest(300000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, [])
            ->withPromissoryNoteSchedule($schedule);
    }

    public function testSameDayRowsAndTwentyFourRowsPass(): void
    {
        $sameDay = (new DirectMethodPaymentRequest(300000, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, [])
            ->withPromissoryNoteSchedule([['amount' => 150000, 'dueDate' => '2030-01-15'], ['amount' => 150000, 'dueDate' => '2030-01-15']])
            ->toArray();
        $this->assertCount(2, $sameDay['promissoryNote']['schedule']);

        $longest = (new DirectMethodPaymentRequest(24 * 12500, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, [])
            ->withPromissoryNoteSchedule(array_fill(0, 24, ['amount' => 12500, 'dueDate' => '2030-01-15']))
            ->toArray();
        $this->assertCount(24, $longest['promissoryNote']['schedule']);
    }

    public function testTheScheduleMustAddUpToAmount(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('adds up to 300000; it must equal amount (300001)');

        (new DirectMethodPaymentRequest(300001, 'TRY', 'O'))
            ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, [])
            ->withPromissoryNoteSchedule(self::SCHEDULE)
            ->toArray();
    }

    /** @return array<string,array{0:mixed}> */
    public static function badNoteCounts(): array
    {
        return ['zero' => [0], 'too many' => [25], 'negative' => [-1], 'leading zero' => ['03'], 'word' => ['three'], 'empty' => [''], 'float' => [2.5]];
    }

    /** @param mixed $count */
    #[DataProvider('badNoteCounts')]
    public function testNoteCountsOutsideOneToTwentyFourAreRefused($count): void
    {
        $this->expectException(ConfigurationException::class);

        (new DirectMethodPaymentRequest(300000, 'TRY', 'O'))->withPromissoryNoteCount($count);
    }

    // --- PaymentClient::directMethodPayment ----------------------------------------------

    public function testDirectMethodPaymentSendsThePromissoryNoteBodySignedAndKeyed(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, [
            'status' => 'success',
            'code' => 'SUCCESS',
            'data' => ['paymentId' => self::PAYMENT_ID, 'orderId' => 'ORD-PN-1', 'status' => 'CAPTURED', 'paymentMethod' => 'PROMISSORY_NOTE', 'providerCode' => 'TEST_PROVIDER'],
        ]);

        $result = (new PaymentClient($api))->directMethodPayment($this->promissoryRequest(), 'pay:ORD-PN-1');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/direct/payment', $call['url']);
        $this->assertSame('pay:ORD-PN-1', $call['headers']['Idempotency-Key']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertArrayHasKey('Digest', $call['headers']);
        $this->assertSame($this->promissoryRequest()->toArray(), self::body($http));
        $this->assertSame('CAPTURED', $result->status());
        $this->assertNull($result->action());
    }

    public function testABlockErrorFromTheGatewayCarriesTheFieldPath(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(400, [
            'status' => 'failure',
            'code' => 'payment.method.block_invalid',
            'message' => 'Invalid',
            'details' => ['field' => 'promissoryNote.schedule[1].dueDate', 'errors' => [['field' => 'promissoryNote.schedule[1].dueDate', 'reason' => 'not_in_future']]],
        ]);

        try {
            (new PaymentClient($api))->directMethodPayment($this->promissoryRequest(), 'pay:ORD-PN-1');
            $this->fail('A 400 must throw.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('payment.method.block_invalid', $e->getErrorCode());
            $this->assertSame('not_in_future', $e->getResponseBody()['details']['errors'][0]['reason']);
        }
    }

    // --- Intent --------------------------------------------------------------------------

    public function testAnIntentCarriesPromissoryNoteScheduleOnly(): void
    {
        $body = (new CreateIntentRequest(300000, 'TRY', 'ORD-PN-I1'))
            ->withPaymentMethod(PaymentMethod::PROMISSORY_NOTE)
            ->withPromissoryNoteSchedule(self::SCHEDULE)
            ->toArray();

        $this->assertSame(['schedule' => self::SCHEDULE], $body['promissoryNote']);
        $this->assertSame('PROMISSORY_NOTE', $body['paymentMethod']);

        $unpinned = (new CreateIntentRequest(300000, 'TRY', 'O'))->withPromissoryNoteSchedule(self::SCHEDULE)->toArray();
        $this->assertArrayNotHasKey('paymentMethod', $unpinned);
    }

    public function testAnIntentScheduleNeedsTheMatchingMethodAndTotal(): void
    {
        try {
            (new CreateIntentRequest(300000, 'TRY', 'O'))->withPaymentMethod('CARD')->withPromissoryNoteSchedule(self::SCHEDULE)->toArray();
            $this->fail('A schedule with paymentMethod CARD must be refused.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('cannot be sent with paymentMethod CARD', $e->getMessage());
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must equal amount');
        (new CreateIntentRequest(300001, 'TRY', 'O'))->withPromissoryNoteSchedule(self::SCHEDULE)->toArray();
    }

    // --- Promissory-notes endpoints ------------------------------------------------------

    public function testGetPromissoryNotesReadsTheNotes(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, self::notesEnvelope());

        $notes = (new PaymentClient($api))->getPromissoryNotes(self::PAYMENT_ID);

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/' . self::PAYMENT_ID . '/promissory-notes', $call['url']);
        $this->assertNull($call['body']);
        // The API surface accepts only a signed key request (Bearer + Ed25519).
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertSame(self::PAYMENT_ID, $notes->paymentId());
        $this->assertSame('PROMISSORY_NOTE', $notes->documentType());
        $this->assertSame('ROW-0001', $notes->rowSerialNumber());
        $this->assertSame(300000, $notes->totalAmount());
        $this->assertCount(2, $notes->notes());
        $second = $notes->notes()[1];
        $this->assertSame('NOTE-0002', $second->serialNumber());
        $this->assertSame(2, $second->sequence());
        $this->assertSame(200000, $second->amount());
        $this->assertSame(PromissoryNoteStatus::PAID, $second->status());
        $this->assertSame('2030-02-16T09:00:00.000Z', $second->paidAt());
        $this->assertNull($second->cancelledAt());
        $this->assertSame([], $notes->cancelResults());
    }

    public function testAPaymentWithoutNotesIsA404(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(404, ['status' => 'failure', 'code' => 'payment.promissory_note.not_found', 'message' => 'Not found']);

        try {
            (new PaymentClient($api))->getPromissoryNotes(self::PAYMENT_ID);
            $this->fail('A 404 must throw.');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('payment.promissory_note.not_found', $e->getErrorCode());
        }
    }

    public function testCancelWithoutSerialNumbersSendsNoBody(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, self::notesEnvelope(['cancelResults' => [
            ['serialNumber' => 'NOTE-0001', 'result' => 'CANCELLED', 'errorCode' => null],
            ['serialNumber' => 'NOTE-0002', 'result' => 'NOT_CANCELLABLE', 'errorCode' => 'payment.promissory_note.not_cancellable'],
        ]]));

        $notes = (new PaymentClient($api))->cancelPromissoryNotes(self::PAYMENT_ID);

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/' . self::PAYMENT_ID . '/promissory-notes/cancel', $call['url']);
        $this->assertNull($call['body']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertArrayNotHasKey('Digest', $call['headers']);
        $this->assertArrayNotHasKey('Idempotency-Key', $call['headers']);
        $this->assertSame([
            ['serialNumber' => 'NOTE-0001', 'result' => PromissoryNoteCancelResult::CANCELLED, 'errorCode' => null],
            ['serialNumber' => 'NOTE-0002', 'result' => PromissoryNoteCancelResult::NOT_CANCELLABLE, 'errorCode' => PromissoryNoteCancelResult::ERROR_NOT_CANCELLABLE],
        ], $notes->cancelResults());
    }

    public function testCancelGivenSerialNumbersSignedAndKeyed(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, self::notesEnvelope());

        (new PaymentClient($api))->cancelPromissoryNotes(self::PAYMENT_ID, ['NOTE-0001'], 'cancel-pn-000001');

        $call = $http->lastCall();
        $this->assertSame(['serialNumbers' => ['NOTE-0001']], self::body($http));
        $this->assertArrayHasKey('Digest', $call['headers']);
        $this->assertSame('cancel-pn-000001', $call['headers']['Idempotency-Key']);
    }

    /** @return array<string,array{0:array<mixed>}> */
    public static function badSerialNumbers(): array
    {
        return [
            'empty' => [[]],
            'too short' => [['NO']],
            'space' => [['NOTE 0001']],
            'not a string' => [[7]],
            'too many' => [array_map(static fn (int $i): string => 'NOTE-' . $i, range(1000, 1024))],
        ];
    }

    /** @param array<mixed> $serialNumbers */
    #[DataProvider('badSerialNumbers')]
    public function testCancelSerialNumbersAreCheckedBeforeSending(array $serialNumbers): void
    {
        [$api, $http] = $this->api();

        try {
            (new PaymentClient($api))->cancelPromissoryNotes(self::PAYMENT_ID, $serialNumbers);
            $this->fail('Invalid serial numbers must be refused.');
        } catch (ConfigurationException $e) {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function testDownloadReturnsThePdfBytes(): void
    {
        [$api, $http] = $this->api();
        $pdf = "%PDF-1.7\n%test\n";
        $http->push(new HttpResponse(200, ['content-type' => 'application/pdf'], $pdf));

        $document = (new PaymentClient($api))->downloadPromissoryNoteDocument(self::PAYMENT_ID, PromissoryNoteDocument::LOCALE_EN);

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://gw.example.com/api/v1/payments/' . self::PAYMENT_ID . '/promissory-notes/document?locale=en-GB', $call['url']);
        $this->assertStringContainsString('application/pdf', $call['headers']['Accept']);
        $this->assertArrayHasKey('X-Signature', $call['headers']);
        $this->assertSame('application/pdf', $document->contentType());
        $this->assertSame($pdf, $document->bytes());
    }

    public function testDownloadRefusesAnUnknownLocaleAndReadsErrorsAsJson(): void
    {
        [$api, $http] = $this->api();

        try {
            (new PaymentClient($api))->downloadPromissoryNoteDocument(self::PAYMENT_ID, 'tr');
            $this->fail('An unknown locale must be refused.');
        } catch (ConfigurationException $e) {
            $this->assertSame(0, $http->callCount());
        }

        $http->pushJson(503, ['status' => 'failure', 'code' => 'payment.promissory_note.document_unavailable', 'message' => 'Try later']);
        try {
            (new PaymentClient($api))->downloadPromissoryNoteDocument(self::PAYMENT_ID);
            $this->fail('A 503 must throw.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('payment.promissory_note.document_unavailable', $e->getErrorCode());
            $this->assertSame('https://gw.example.com/api/v1/payments/' . self::PAYMENT_ID . '/promissory-notes/document', $http->lastCall()['url']);
        }
    }

    public function testEmptyPaymentIdsAreRefused(): void
    {
        [$api, $http] = $this->api();
        $this->expectException(ConfigurationException::class);

        (new PaymentClient($api))->getPromissoryNotes(' ');
    }

    // --- Input field definitions and the webhook -----------------------------------------

    public function testInputFieldsReadConditionsAndGroupItems(): void
    {
        $group = new MethodInputField([
            'key' => 'guarantors',
            'type' => 'GROUP',
            'required' => false,
            'requiredWhen' => null,
            'itemFields' => [['key' => 'name', 'type' => 'TEXT', 'required' => true, 'requiredWhen' => null, 'itemFields' => [], 'minItems' => null, 'maxItems' => null]],
            'minItems' => null,
            'maxItems' => 5,
        ]);
        $this->assertNull($group->requiredWhen());
        $this->assertSame('name', $group->itemFields()[0]->key());
        $this->assertNull($group->minItems());
        $this->assertSame(5, $group->maxItems());

        $conditional = new MethodInputField(['key' => 'companyTitle', 'type' => 'TEXT', 'requiredWhen' => ['field' => 'debtorType', 'equals' => 'CORPORATE'], 'itemFields' => []]);
        $this->assertSame(['field' => 'debtorType', 'equals' => 'CORPORATE'], $conditional->requiredWhen());
        $this->assertSame([], $conditional->itemFields());
    }

    public function testAPromissoryNoteWebhookIsRead(): void
    {
        $event = new WebhookEvent('evt_1', PromissoryNoteEvents::PAID, '2030-01-16T03:00:00.000Z', [
            'intentId' => self::PAYMENT_ID,
            'orderId' => 'ORD-PN-1',
            'status' => 'CAPTURED',
            'paymentMethod' => 'PROMISSORY_NOTE',
            'promissoryNote' => ['serialNumber' => 'NOTE-0001', 'sequence' => 1, 'amountMinor' => '100000', 'dueDate' => '2030-01-15', 'status' => 'PAID', 'occurredAt' => '2030-01-16T02:00:00.000Z'],
        ]);

        $note = PromissoryNoteEvent::fromEvent($event);

        $this->assertSame(self::PAYMENT_ID, $note->paymentId());
        $this->assertSame('ORD-PN-1', $note->orderId());
        $this->assertSame('NOTE-0001', $note->serialNumber());
        $this->assertSame(1, $note->sequence());
        $this->assertSame('100000', $note->amountMinor());
        $this->assertSame(PromissoryNoteStatus::PAID, $note->status());

        $this->expectException(ConfigurationException::class);
        PromissoryNoteEvent::fromEvent(new WebhookEvent('evt_2', 'payment.captured', '2030-01-16T03:00:00.000Z', []));
    }

    // --- Address reference ---------------------------------------------------------------

    public function testTheAddressReferenceReadsTheThreeLevels(): void
    {
        [$api, $http] = $this->api();
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => ['items' => [['code' => '06', 'name' => 'Ankara']]]]);
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => ['items' => []]]);
        $http->pushJson(200, ['status' => 'success', 'code' => 'SUCCESS', 'data' => ['items' => []]]);
        $reference = new ReferenceClient($api);

        $this->assertSame([['code' => '06', 'name' => 'Ankara']], $reference->listProvinces());
        $this->assertSame([], $reference->listDistricts('06'), 'an empty level is returned as an empty list');
        $this->assertSame([], $reference->listNeighborhoods('1001'));

        $this->assertSame([
            'https://gw.example.com/api/v1/reference/tr-address/provinces',
            'https://gw.example.com/api/v1/reference/tr-address/districts?provinceCode=06',
            'https://gw.example.com/api/v1/reference/tr-address/neighborhoods?districtCode=1001',
        ], array_column($http->calls, 'url'));
    }

    public function testAddressCodesAreCheckedBeforeSending(): void
    {
        [$api, $http] = $this->api();
        $reference = new ReferenceClient($api);

        foreach (['6', '00', '82', ''] as $provinceCode) {
            try {
                $reference->listDistricts($provinceCode);
                $this->fail('provinceCode ' . $provinceCode . ' must be refused.');
            } catch (ConfigurationException $e) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['', 'abc', str_repeat('1', 13)] as $districtCode) {
            try {
                $reference->listNeighborhoods($districtCode);
                $this->fail('districtCode ' . $districtCode . ' must be refused.');
            } catch (ConfigurationException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $http->callCount());
    }
}
