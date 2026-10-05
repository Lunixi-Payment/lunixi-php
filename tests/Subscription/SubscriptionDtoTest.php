<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Subscription\AddCardRequest;
use Lunixi\Sdk\Subscription\BillingRule;
use Lunixi\Sdk\Subscription\CreatePlanRequest;
use Lunixi\Sdk\Subscription\CreateSubscriptionRequest;
use Lunixi\Sdk\Subscription\Subscription;
use Lunixi\Sdk\Subscription\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

final class SubscriptionDtoTest extends TestCase
{
    public function testCreateSubscriptionRequiresIdempotencyKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new CreateSubscriptionRequest('cus_1', 'plan_1', '   ');
    }

    public function testCreateSubscriptionRequiresCustomerAndPlan(): void
    {
        $this->expectException(ConfigurationException::class);
        new CreateSubscriptionRequest('', 'plan_1', 'idem');
    }

    public function testBillingRuleRejectsUnknownFrequency(): void
    {
        $this->expectException(ConfigurationException::class);
        new BillingRule('FORTNIGHTLY', 1);
    }

    public function testBillingRuleToArray(): void
    {
        $rule = (new BillingRule('monthly', 2))->withTimezone('Europe/Istanbul')->withLastDayOfMonthFix(true);
        $arr = $rule->toArray();
        $this->assertSame('MONTHLY', $arr['frequency']);
        $this->assertSame(2, $arr['interval']);
        $this->assertSame('Europe/Istanbul', $arr['timezone']);
        $this->assertTrue($arr['lastDayOfMonthFix']);
    }

    public function testCreatePlanRejectsUnsupportedCurrency(): void
    {
        $this->expectException(ConfigurationException::class);
        new CreatePlanRequest('prod_1', 'pro', 'Pro', 9900, 'XXX', new BillingRule('MONTHLY', 1));
    }

    public function testCreatePlanToArrayCarriesBillingRule(): void
    {
        $req = (new CreatePlanRequest('prod_1', 'pro-monthly', 'Pro', 9900, 'try', new BillingRule('MONTHLY', 1)))
            ->withTrialPeriodDays(14)
            ->withDunningScheduleDays([1, 3, 7]);
        $arr = $req->toArray();
        $this->assertSame('prod_1', $arr['productId']);
        $this->assertSame(9900, $arr['price']);
        $this->assertSame('TRY', $arr['currency']);
        $this->assertSame('MONTHLY', $arr['billing']['frequency']);
        $this->assertSame(14, $arr['trialPeriodDays']);
        $this->assertSame([1, 3, 7], $arr['dunningScheduleDays']);
    }

    public function testAddCardRequiresToken(): void
    {
        $this->expectException(ConfigurationException::class);
        new AddCardRequest('cus_1', '');
    }

    public function testSubscriptionModelStatusHelpers(): void
    {
        $trialing = new Subscription(['id' => 's1', 'status' => SubscriptionStatus::TRIALING]);
        $this->assertTrue($trialing->isTrialing());
        $this->assertTrue($trialing->isActive()); // TRIALING is a live/billable state
        $this->assertFalse($trialing->isTerminal());

        $canceled = new Subscription(['id' => 's2', 'status' => SubscriptionStatus::CANCELED]);
        $this->assertTrue($canceled->isTerminal());
        $this->assertFalse($canceled->isActive());
    }
}
