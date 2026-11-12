<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\GiftCard\GiftCardSellerFunding;

final class GiftCardSellerFundingTest extends TestCase
{
    public function testPartialPaymentFundsSellersFromChargeAndPlatformBalance(): void
    {
        self::assertSame([
            ['manufacturer_id' => 1, 'charge_cents' => 2000, 'platform_cents' => 4000],
            ['manufacturer_id' => 2, 'charge_cents' => 500, 'platform_cents' => 1000],
        ], GiftCardSellerFunding::plan(7500, 5000, [
            ['manufacturer_id' => 1, 'amount' => 6000], ['manufacturer_id' => 2, 'amount' => 1500],
        ]));
    }

    public function testFullGiftCardFundingUsesPlatformBalanceWithoutACharge(): void
    {
        self::assertSame([['manufacturer_id' => 1, 'charge_cents' => 0, 'platform_cents' => 2400]],
            GiftCardSellerFunding::plan(3000, 3000, [['manufacturer_id' => 1, 'amount' => 2400]]));
    }

    public function testRoundingNeverOverallocatesTheStripeCharge(): void
    {
        $plan = GiftCardSellerFunding::plan(3, 2, [
            ['manufacturer_id' => 1, 'amount' => 1], ['manufacturer_id' => 2, 'amount' => 2],
        ]);
        self::assertLessThanOrEqual(1, array_sum(array_column($plan, 'charge_cents')));
        self::assertSame(3, array_sum(array_column($plan, 'platform_cents')) + array_sum(array_column($plan, 'charge_cents')));
    }

    public function testSellerAllocationsCannotExceedOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GiftCardSellerFunding::plan(100, 50, [['manufacturer_id' => 1, 'amount' => 101]]);
    }
}
