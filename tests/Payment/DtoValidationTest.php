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
use Lunixi\Sdk\Payment\StoreCardRequest;
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
            ->withPrice(1200)
            ->withPaidPrice(1000)
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
        $this->assertSame(1200, $body['price']);
        $this->assertSame(1000, $body['paidPrice']);
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
        $this->assertSame(1000, $body['paidPrice']);
        $this->assertSame('TRY', $body['currency']);
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
