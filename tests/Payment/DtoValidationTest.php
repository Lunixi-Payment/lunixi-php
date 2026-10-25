<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Payment\Address;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\Buyer;
use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\DirectPaymentRequest;
use Lunixi\Sdk\Payment\InstallmentOptionsRequest;
use Lunixi\Sdk\Payment\InsuranceItemCategory;
use Lunixi\Sdk\Payment\StoreCardRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DtoValidationTest extends TestCase
{
    public function testCreateIntentRejectsNonPositiveAmount(): void
    {
        $this->expectException(ConfigurationException::class);
        new CreateIntentRequest(0, 'TRY', 'o1');
    }

    public function testCreateIntentRejectsUnsupportedCurrency(): void
    {
        $this->expectException(ConfigurationException::class);
        new CreateIntentRequest(100, 'XXX', 'o1');
    }

    public function testCreateIntentRejectsEmptyOrderId(): void
    {
        $this->expectException(ConfigurationException::class);
        new CreateIntentRequest(100, 'TRY', '  ');
    }

    public function testCreateIntentNormalisesCurrencyAndComposesNestedDtos(): void
    {
        $request = (new CreateIntentRequest(1000, 'eur', 'o1'))
            ->withBuyer(new Buyer([
                'name' => 'Ada', 'surname' => 'L', 'identityNumber' => '11111111111',
                'email' => 'a@b.co', 'gsmNumber' => '+90555', 'city' => 'Istanbul',
                'country' => 'TR', 'zipCode' => '34000', 'ip' => '1.2.3.4',
            ]))
            ->withCardUserKey('cust-card-1')
            ->withPaymentMethod('CARD')
            ->withForce3D(true)
            ->withSettings(['threeD' => ['mode' => 'auto', 'forceAmountMinor' => '500000']])
            ->withBillingAddress(new Address([
                'address' => 'Street 1', 'zipCode' => '34000', 'contactName' => 'Ada',
                'city' => 'Istanbul', 'country' => 'TR',
            ]))
            ->withBasketItems([
                new BasketItem(['id' => 'i1', 'price' => 1000, 'name' => 'Widget', 'category1' => 'Cat', 'itemType' => BasketItem::TYPE_PHYSICAL]),
            ]);

        $body = $request->toArray();
        $this->assertSame('EUR', $body['currency']);
        $this->assertSame(1000, $body['amount']);
        $this->assertArrayNotHasKey('price', $body);
        $this->assertArrayNotHasKey('paidPrice', $body);
        $this->assertSame('cust-card-1', $body['cardUserKey']);
        $this->assertSame('CARD', $body['paymentMethod']);
        $this->assertTrue($body['force3D']);
        $this->assertSame('auto', $body['settings']['threeD']['mode']);
        $this->assertSame('Ada', $body['buyer']['name']);
        $this->assertSame('Street 1', $body['billingAddress']['address']);
        $this->assertSame(1000, $body['basketItems'][0]['price']);
        $this->assertSame('PHYSICAL', $body['basketItems'][0]['itemType']);
    }

    public function testBuyerRequiresAllMandatoryFields(): void
    {
        $this->expectException(ConfigurationException::class);
        new Buyer(['name' => 'Ada']); // missing surname/email/…
    }

    public function testAddressRequiresAllFields(): void
    {
        $this->expectException(ConfigurationException::class);
        new Address(['address' => 'x']);
    }

    public function testBasketItemRejectsNegativePrice(): void
    {
        $this->expectException(ConfigurationException::class);
        new BasketItem(['id' => 'i1', 'price' => -5, 'name' => 'n', 'category1' => 'c', 'itemType' => 'PHYSICAL']);
    }

    public function testBasketItemRejectsItemTypeOutsideTheGatewaySet(): void
    {
        $this->expectException(ConfigurationException::class);
        new BasketItem(['id' => 'i1', 'price' => 100, 'name' => 'n', 'category1' => 'c', 'itemType' => 'DIGITAL']);
    }

    public function testBasketItemCopiesTheInsuranceFields(): void
    {
        $context = ['transportMode' => 'TRAIN', 'departureAt' => '2025-12-23T12:50:00+03:00', 'isPromotional' => false];
        $item = new BasketItem([
            'id' => 'item-1',
            'price' => 100000,
            'name' => 'Izmir-Ankara train ticket',
            'category1' => 'ticket',
            'itemType' => BasketItem::TYPE_VIRTUAL,
            'insuranceCategory' => InsuranceItemCategory::TICKET,
            'insuranceTypes' => ['TICKET_CANCELLATION'],
            'insuranceEligible' => true,
            'insuranceValueAmount' => 100000,
            'insuranceContext' => $context,
        ]);

        $body = (new CreateIntentRequest(100000, 'TRY', 'o1'))->withBasketItems([$item])->toArray();
        $sent = $body['basketItems'][0];
        $this->assertSame('TICKET', $sent['insuranceCategory']);
        $this->assertSame(['TICKET_CANCELLATION'], $sent['insuranceTypes']);
        $this->assertTrue($sent['insuranceEligible']);
        $this->assertSame(100000, $sent['insuranceValueAmount']);
        $this->assertSame($context, $sent['insuranceContext']);
        $this->assertSame(
            '{"transportMode":"TRAIN","departureAt":"2025-12-23T12:50:00+03:00","isPromotional":false}',
            json_encode($sent['insuranceContext'])
        );
    }

    public function testInsuranceItemCategoriesMatchTheGatewayList(): void
    {
        $this->assertSame([
            'TICKET', 'HOTEL', 'EVENT', 'DEVICE', 'ELECTRONICS', 'VEHICLE', 'TRAVEL', 'HOME', 'HEALTH',
            'PET', 'MICROMOBILITY', 'MUSICAL_INSTRUMENT', 'SHIPMENT', 'EDUCATION',
        ], InsuranceItemCategory::ALL);

        $context = ['petSpecies' => 'CAT', 'petBreedCode' => 'SAMPLE_BREED'];
        $item = new BasketItem([
            'id' => 'item-2', 'price' => 50000, 'name' => 'Pet care plan', 'category1' => 'pet', 'itemType' => BasketItem::TYPE_VIRTUAL,
            'insuranceCategory' => InsuranceItemCategory::PET, 'insuranceContext' => $context,
        ]);
        $sent = $item->toArray();
        $this->assertSame('PET', $sent['insuranceCategory']);
        $this->assertSame($context, $sent['insuranceContext']);
    }

    public function testBasketItemWithoutInsuranceFieldsIsUnchanged(): void
    {
        $item = new BasketItem([
            'id' => 'i1', 'price' => 1000, 'name' => 'Widget', 'category1' => 'Cat', 'itemType' => 'PHYSICAL',
            'insuranceCategory' => null, 'insuranceContext' => [],
        ]);
        $this->assertSame(
            ['id' => 'i1', 'price' => 1000, 'name' => 'Widget', 'category1' => 'Cat', 'itemType' => 'PHYSICAL'],
            $item->toArray()
        );
    }

    public function testBasketItemCopiesTheInsuranceDataWithOneTypeObjectForTheItem(): void
    {
        // Synthetic values: a made-up passport number, a sample PNR and ticket number.
        $insuranceData = [
            'insured' => [['identityType' => 'PASSPORT', 'identityNumber' => 'P0000000', 'birthDate' => '2000-01-01', 'email' => 'insured@example.com']],
            'tickets' => ['carrier' => 'Sample Rail', 'priceAmount' => 100000, 'pnr' => 'SAMPLE1', 'ticketNumber' => 'SAMPLE-0001'],
        ];
        $item = new BasketItem([
            'id' => 'item-1', 'price' => 100000, 'name' => 'Izmir-Ankara train ticket', 'category1' => 'ticket',
            'itemType' => BasketItem::TYPE_VIRTUAL, 'insuranceCategory' => InsuranceItemCategory::TICKET,
            'insuranceTypes' => ['TICKET_CANCELLATION'], 'insuranceData' => $insuranceData,
        ]);

        $sent = (new CreateIntentRequest(100000, 'TRY', 'o1'))->withBasketItems([$item])->toArray()['basketItems'][0];
        $this->assertSame($insuranceData, $sent['insuranceData']);
        $this->assertSame(
            '{"carrier":"Sample Rail","priceAmount":100000,"pnr":"SAMPLE1","ticketNumber":"SAMPLE-0001"}',
            json_encode($sent['insuranceData']['tickets']),
            'tickets is one object for the item'
        );

        $everyRoot = [];
        foreach (BasketItem::INSURANCE_DATA_TYPE_ROOTS as $root) {
            $everyRoot[$root] = ['startDate' => '2026-01-02'];
        }
        $this->assertSame([
            'tickets', 'stay', 'device', 'product', 'vehicle', 'trip', 'rental', 'instrument',
            'event', 'accident', 'procedure', 'pet', 'residence', 'abroadStay', 'property', 'health',
        ], BasketItem::INSURANCE_DATA_TYPE_ROOTS, 'the type roots of the gateway (insurance-data-fields.ts)');
        $all = new BasketItem([
            'id' => 'item-2', 'price' => 1000, 'name' => 'n', 'category1' => 'c', 'itemType' => 'VIRTUAL',
            'insuranceCategory' => InsuranceItemCategory::TRAVEL, 'insuranceData' => ['policyholder' => ['firstName' => 'Sample']] + $everyRoot,
        ]);
        $this->assertSame(array_merge(['policyholder'], BasketItem::INSURANCE_DATA_TYPE_ROOTS), array_keys($all->toArray()['insuranceData']));
    }

    public function testBasketItemLeavesOutAnEmptyOrNullInsuranceData(): void
    {
        $base = ['id' => 'i1', 'price' => 1000, 'name' => 'Widget', 'category1' => 'Cat', 'itemType' => 'PHYSICAL'];
        foreach ([null, [], ['tickets' => null]] as $insuranceData) {
            $this->assertSame($base, (new BasketItem($base + ['insuranceData' => $insuranceData]))->toArray());
        }
        $sent = (new BasketItem($base + [
            'insuranceCategory' => InsuranceItemCategory::DEVICE,
            'insuranceData' => ['device' => ['serialNumber' => 'SAMPLE-SN-1'], 'insured' => null],
        ]))->toArray();
        $this->assertSame(['device' => ['serialNumber' => 'SAMPLE-SN-1']], $sent['insuranceData'], 'a null root is not sent');
    }

    public function testBasketItemInsuranceDataErrorsNameThePathNotTheValue(): void
    {
        try {
            new BasketItem([
                'id' => 'i1', 'price' => 1000, 'name' => 'n', 'category1' => 'c', 'itemType' => 'VIRTUAL',
                'insuranceCategory' => InsuranceItemCategory::TICKET,
                'insuranceData' => ['insured' => [['identityNumber' => 'P0000000', 'email' => null]]],
            ]);
            $this->fail('a null field was accepted');
        } catch (ConfigurationException $error) {
            $this->assertStringContainsString('insuranceData.insured[0].email', $error->getMessage());
            $this->assertStringNotContainsString('P0000000', $error->getMessage());
        }
    }

    public function testBasketItemAcceptsInsuranceEligibleFalseAlone(): void
    {
        $item = new BasketItem([
            'id' => 'i1', 'price' => 1000, 'name' => 'Gift card', 'category1' => 'Cat', 'itemType' => 'VIRTUAL',
            'insuranceEligible' => false,
        ]);
        $this->assertFalse($item->toArray()['insuranceEligible']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidInsuranceFields(): array
    {
        return [
            'unknown category' => [['insuranceCategory' => 'CAR']],
            'types without category' => [['insuranceTypes' => ['TICKET_CANCELLATION']]],
            'value without category' => [['insuranceValueAmount' => 1000]],
            'context without category' => [['insuranceContext' => ['transportMode' => 'BUS']]],
            'type code format' => [['insuranceCategory' => 'TICKET', 'insuranceTypes' => ['ticket_cancellation']]],
            'repeated type code' => [['insuranceCategory' => 'TICKET', 'insuranceTypes' => ['TICKET_CANCELLATION', 'TICKET_CANCELLATION']]],
            'too many type codes' => [['insuranceCategory' => 'TICKET', 'insuranceTypes' => array_map(
                static fn (int $i): string => 'TYPE_' . $i,
                range(1, 11)
            )]],
            'types not a list' => [['insuranceCategory' => 'TICKET', 'insuranceTypes' => 'TICKET_CANCELLATION']],
            'eligible as string' => [['insuranceEligible' => 'false']],
            'zero value' => [['insuranceCategory' => 'DEVICE', 'insuranceValueAmount' => 0]],
            'value as float' => [['insuranceCategory' => 'DEVICE', 'insuranceValueAmount' => 10.5]],
            'context as list' => [['insuranceCategory' => 'TICKET', 'insuranceContext' => ['BUS']]],
            'data without category' => [['insuranceData' => ['tickets' => ['pnr' => 'SAMPLE1']]]],
            'data as list' => [['insuranceCategory' => 'TICKET', 'insuranceData' => [['pnr' => 'SAMPLE1']]]],
            'data as string' => [['insuranceCategory' => 'TICKET', 'insuranceData' => 'SAMPLE1']],
            'data unknown root' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['ticket' => ['pnr' => 'SAMPLE1']]]],
            'data list type object as list' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['tickets' => [['pnr' => 'SAMPLE1']]]]],
            'data type object with basketItemId' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['tickets' => ['basketItemId' => 'i1', 'pnr' => 'SAMPLE1']]]],
            'data empty type object' => [['insuranceCategory' => 'DEVICE', 'insuranceData' => ['device' => []]]],
            'data policyholder as string' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['policyholder' => 'Sample']]],
            'data insured as object' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['insured' => ['identityType' => 'PASSPORT']]]],
            'data insured empty' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['insured' => []]]],
            'data insured over ten' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['insured' => array_fill(0, 11, ['birthDate' => '2000-01-01'])]]],
            'data insured entry not an object' => [['insuranceCategory' => 'TICKET', 'insuranceData' => ['insured' => ['P0000000']]]],
            'data nested null' => [['insuranceCategory' => 'DEVICE', 'insuranceData' => ['device' => ['serialNumber' => null]]]],
            'data over 16 KB' => [['insuranceCategory' => 'DEVICE', 'insuranceData' => ['device' => ['serialNumber' => str_repeat('x', 16384)]]]],
        ];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('invalidInsuranceFields')]
    public function testBasketItemRejectsInvalidInsuranceFields(array $fields): void
    {
        $this->expectException(ConfigurationException::class);
        new BasketItem(['id' => 'i1', 'price' => 1000, 'name' => 'n', 'category1' => 'c', 'itemType' => 'VIRTUAL'] + $fields);
    }

    public function testDirectPaymentRequestMapsBackendProcessPaymentDto(): void
    {
        $request = (new DirectPaymentRequest(
            1000,
            'try',
            'o1',
            new CardDetails([
                'cardHolderName' => 'Ada L',
                'cardNumber' => '5400000000000004',
                'expireMonth' => '12',
                'expireYear' => '28',
                'cvcNumber' => '123',
                'cardSave' => true,
            ]),
            new Buyer([
                'name' => 'Ada', 'surname' => 'L', 'identityNumber' => '11111111111',
                'email' => 'a@b.co', 'gsmNumber' => '+90555', 'city' => 'Istanbul',
                'country' => 'TR', 'zipCode' => '34000', 'ip' => '1.2.3.4',
            ]),
            new Address([
                'address' => 'Street 1', 'zipCode' => '34000', 'contactName' => 'Ada',
                'city' => 'Istanbul', 'country' => 'TR',
            ]),
            [
                new BasketItem(['id' => 'i1', 'price' => 1000, 'name' => 'Widget', 'category1' => 'Cat', 'itemType' => BasketItem::TYPE_PHYSICAL]),
            ]
        ))
            ->withInstallment(3, 'iq_1')
            ->withCallbackUrl('https://shop/cb')
            ->withCustomerId('cust_1')
            ->withMetadata(['order' => 'o1']);

        $body = $request->toArray();
        $this->assertSame(1000, $body['amount']);
        $this->assertArrayNotHasKey('paidPrice', $body);
        $this->assertSame('TRY', $body['currency']);
        $this->assertSame(3, $body['installment']);
        $this->assertSame('iq_1', $body['installmentQuoteToken']);
        $this->assertTrue($body['card']['cardSave']);
        $this->assertSame('Ada', $body['buyer']['name']);
        $this->assertSame('Street 1', $body['billingAddress']['address']);
        $this->assertSame('Widget', $body['basketItems'][0]['name']);
        $this->assertSame('o1', $body['metadata']['order']);
    }

    public function testDirectPaymentRequestRequiresBasketItems(): void
    {
        $this->expectException(ConfigurationException::class);
        new DirectPaymentRequest(
            1000,
            'TRY',
            'o1',
            new CardDetails(['cardNumber' => '5400000000000004']),
            new Buyer([
                'name' => 'Ada', 'surname' => 'L', 'identityNumber' => '11111111111',
                'email' => 'a@b.co', 'gsmNumber' => '+90555', 'city' => 'Istanbul',
                'country' => 'TR', 'zipCode' => '34000', 'ip' => '1.2.3.4',
            ]),
            new Address([
                'address' => 'Street 1', 'zipCode' => '34000', 'contactName' => 'Ada',
                'city' => 'Istanbul', 'country' => 'TR',
            ]),
            []
        );
    }

    public function testInstallmentAndStoreCardRequestsMapBackendDtos(): void
    {
        $installments = (new InstallmentOptionsRequest(1000, 'TRY'))
            ->withBinOrPan('54000000')
            ->withInstallment(3)
            ->withFormat('data')
            ->toArray();
        $this->assertSame(1000, $installments['amount']);
        $this->assertSame('54000000', $installments['binOrPan']);
        $this->assertSame(3, $installments['installment']);

        $storeCard = (new StoreCardRequest(
            new CardDetails(['cardNumber' => '5400000000000004']),
            'cust_1',
            'https://shop/cards/callback'
        ))->withCurrency('try')->toArray();
        $this->assertSame('cust_1', $storeCard['cardUserKey']);
        $this->assertSame('TRY', $storeCard['currency']);
    }
}
