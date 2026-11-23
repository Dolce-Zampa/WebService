<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/DzOrderMargin.php';

final class DzOrderMarginTest extends TestCase
{
    private function order($amount = '106.100000', $shipping = '6.100000')
    {
        return ['total_paid_tax_incl' => $amount, 'total_shipping_tax_incl' => $shipping, 'total_wrapping_tax_incl' => '0.000000', 'valid' => 1];
    }

    public function testMultipleSellersAndCustomerShipping()
    {
        $lines = [['total_price_tax_incl' => '60.000000', 'commission' => '20.00'], ['total_price_tax_incl' => '40.000000', 'commission' => '25.00']];
        $result = DzOrderMargin::calculate($this->order(), $lines, '2.00', false);
        self::assertSame('100.00', $result['products']);
        self::assertSame('22.00', $result['commission']);
        self::assertSame('78.00', $result['artisan']);
        self::assertSame('26.10', $result['margin']);
        self::assertSame(['80.00%', '75.00%'], $result['rates']);
    }

    public function testUnmatchedDiscountAndUnknownRatesBlockMargin()
    {
        $badDiscount = DzOrderMargin::calculate($this->order(), [['total_price_tax_incl' => '110.000000', 'commission' => '20.00']], '2.00', false);
        self::assertNull($badDiscount['margin']);
        self::assertContains('Sconti o totali prodotto da riconciliare', $badDiscount['note']);
        $unknown = DzOrderMargin::calculate($this->order(), [['total_price_tax_incl' => '100.000000', 'commission' => '0.00']], '2.00', false);
        self::assertNull($unknown['margin']);
        self::assertContains('Commissione artigiano assente o da verificare', $unknown['note']);
    }

    public function testZeroFeeIsKnownButUnknownAndRefundAreNot()
    {
        $line = [['total_price_tax_incl' => '100.000000', 'commission' => '20.00']];
        self::assertSame('26.10', DzOrderMargin::calculate($this->order(), $line, '0.00', false)['margin']);
        self::assertNull(DzOrderMargin::calculate($this->order(), $line, null, false)['margin']);
        self::assertNull(DzOrderMargin::calculate($this->order(), $line, '0.00', true)['margin']);
        $invalid = $this->order(); $invalid['valid'] = 0;
        self::assertNull(DzOrderMargin::calculate($invalid, $line, '0.00', false)['margin']);
    }

    public function testFeeValidationAndCsvInjection()
    {
        self::assertSame('2.50', DzOrderMargin::feeInput('2,50'));
        self::assertSame('0.00', DzOrderMargin::feeInput('0'));
        self::assertNull(DzOrderMargin::feeInput(''));
        self::assertSame("' =1+1", DzOrderMargin::csvText(' =1+1'));
        self::assertSame('ORD-1', DzOrderMargin::csvText('ORD-1'));
        $this->expectException(InvalidArgumentException::class);
        DzOrderMargin::feeInput('-2');
    }
}
