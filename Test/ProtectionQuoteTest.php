<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\PurchaseProtection\ProtectionQuote;

final class ProtectionQuoteTest extends TestCase
{
    public function testCalculatesAndRoundsWithoutFloatingPoint(): void
    {
        $quote = ProtectionQuote::calculate(3333, 250);
        self::assertSame(3333, $quote->baseCents);
        self::assertSame(250, $quote->rateBasisPoints);
        self::assertSame(83, $quote->priceCents);
        self::assertSame(1, ProtectionQuote::calculate(20, 250)->priceCents);
        self::assertSame(0, ProtectionQuote::calculate(19, 250)->priceCents);
    }

    /** @dataProvider invalidQuotes */
    public function testRejectsInvalidAmountsAndRates(int $base, int $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProtectionQuote::calculate($base, $rate);
    }

    public static function invalidQuotes(): array
    {
        return [[0, 100], [-1, 100], [1000, 0], [1000, 10001], [PHP_INT_MAX, 100]];
    }

    public function testPriceUsesDiscountedCartValueWithoutShipping(): void
    {
        $quote = ProtectionQuote::fromCart([
            'currency_iso' => 'EUR', 'products' => [['id_product' => 1]],
            'totals' => ['grand_total_tax_incl' => 86.90, 'shipping_tax_incl' => 6.90],
        ], '5');
        self::assertSame(8000, $quote->baseCents);
        self::assertSame(500, $quote->rateBasisPoints);
        self::assertSame(400, $quote->priceCents);
        self::assertSame(525, ProtectionQuote::rateFromPercent('5.25'));
    }

    public function testConfiguredPercentageIsUsedForTheQuote(): void
    {
        $previous = $_ENV['PURCHASE_PROTECTION_PERCENT'] ?? null;
        $_ENV['PURCHASE_PROTECTION_PERCENT'] = '7.5';
        try {
            $quote = ProtectionQuote::fromCart([
                'currency_iso' => 'EUR', 'products' => [['id_product' => 1]],
                'totals' => ['grand_total_tax_incl' => 100, 'shipping_tax_incl' => 0],
            ]);
            self::assertSame(750, $quote->priceCents);
        } finally {
            if ($previous === null) unset($_ENV['PURCHASE_PROTECTION_PERCENT']);
            else $_ENV['PURCHASE_PROTECTION_PERCENT'] = $previous;
        }
    }

    /** @dataProvider invalidRates */
    public function testRejectsInvalidConfiguredRates(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProtectionQuote::rateFromPercent($rate);
    }

    public static function invalidRates(): array
    {
        return [[''], ['0'], ['-5'], ['5,0'], ['1.234'], ['100.01'], ['NaN']];
    }

    public function testRejectsMixedCartsUntilVirtualDiscountsCanBeAllocated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProtectionQuote::fromCart(['currency_iso' => 'EUR', 'products' => [
            ['id_product' => 1, 'is_virtual' => false], ['id_product' => 2, 'is_virtual' => true],
        ], 'totals' => ['grand_total_tax_incl' => 100, 'shipping_tax_incl' => 0]], '5');
    }
}
