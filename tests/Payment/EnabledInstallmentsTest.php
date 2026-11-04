<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\CreatePaymentLinkRequest;
use Lunixi\Sdk\Payment\EnabledInstallments;
use Lunixi\Sdk\Payment\InstallmentOptionsRequest;
use Lunixi\Sdk\Payment\PaymentLinkUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `enabledInstallments` narrows the installment counts of one payment. Broken
 * lists are refused before sending; a valid list is sent in ascending order.
 */
final class EnabledInstallmentsTest extends TestCase
{
    public function testIntentSendsTheListInAscendingOrder(): void
    {
        $body = (new CreateIntentRequest('1000', 'TRY', 'o1'))
            ->withEnabledInstallments(3, 1, 2)
            ->toArray();

        $this->assertSame([1, 2, 3], $body['enabledInstallments']);
    }

    public function testIntentSingleInstallmentOnly(): void
    {
        $body = (new CreateIntentRequest('1000', 'TRY', 'o1'))->withEnabledInstallments(1)->toArray();

        $this->assertSame([1], $body['enabledInstallments']);
    }

    public function testIntentWithoutTheListSendsNoField(): void
    {
        $body = (new CreateIntentRequest('1000', 'TRY', 'o1'))->toArray();

        $this->assertArrayNotHasKey('enabledInstallments', $body);
    }

    public function testIntentRejectsAnEmptyCall(): void
    {
        $this->expectException(ConfigurationException::class);
        (new CreateIntentRequest('1000', 'TRY', 'o1'))->withEnabledInstallments();
    }

    /** @return array<string,array{0:array<mixed>}> */
    public static function invalidLists(): array
    {
        return [
            'empty' => [[]],
            'without 1' => [[2, 3]],
            'duplicate' => [[1, 2, 2]],
            'zero' => [[0, 1]],
            'negative' => [[1, -3]],
            'above 36' => [[1, 37]],
            'string' => [[1, '3']],
            'float' => [[1, 3.0]],
            'null' => [[1, null]],
            'bool' => [[true, 2]],
        ];
    }

    /** @param array<mixed> $counts */
    #[DataProvider('invalidLists')]
    public function testRejectsBrokenLists(array $counts): void
    {
        $this->expectException(ConfigurationException::class);
        EnabledInstallments::require($counts);
    }

    public function testAcceptsTheFullRange(): void
    {
        $this->assertSame([1, 36], EnabledInstallments::require([36, 1]));
    }

    public function testDuplicatesAreNotSilentlyRemoved(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('more than once');
        (new CreateIntentRequest('1000', 'TRY', 'o1'))->withEnabledInstallments(1, 3, 3);
    }

    public function testInstallmentTableSendsTheList(): void
    {
        $body = (new InstallmentOptionsRequest('1000', 'TRY'))
            ->withEnabledInstallments(6, 1, 3)
            ->toArray();

        $this->assertSame([1, 3, 6], $body['enabledInstallments']);
    }

    public function testInstallmentTableRejectsAListWithout1(): void
    {
        $this->expectException(ConfigurationException::class);
        (new InstallmentOptionsRequest(null, null, 'intent_1'))->withEnabledInstallments(2, 3);
    }

    public function testPaymentLinkCheckoutSendsTheList(): void
    {
        $body = CreatePaymentLinkRequest::fixed(PaymentLinkUsage::SINGLE_USE, '150000', 'TRY', 'Fee')
            ->withCheckout(false, [], [3, 1])
            ->toArray();

        $this->assertSame(['requireThreeDs' => false, 'paymentMethods' => [], 'enabledInstallments' => [1, 3]], $body['checkout']);
    }

    public function testPaymentLinkCheckoutEmptyListMeansNoRestriction(): void
    {
        $body = CreatePaymentLinkRequest::fixed(PaymentLinkUsage::SINGLE_USE, '150000', 'TRY', 'Fee')
            ->withCheckout(true, ['card'])
            ->toArray();

        $this->assertArrayNotHasKey('enabledInstallments', $body['checkout']);
    }

    public function testPaymentLinkCheckoutRejectsABrokenList(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('checkout.enabledInstallments');
        CreatePaymentLinkRequest::fixed(PaymentLinkUsage::SINGLE_USE, '150000', 'TRY', 'Fee')
            ->withCheckout(false, [], [2, 4]);
    }
}
