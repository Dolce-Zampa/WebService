<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class FixedCarrierRatesTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_reads_the_rate_and_keeps_multiple_prestashop_ranges_visible(): void
    {
        require __DIR__ . '/stubs/fixed-carrier-rates-runtime.php';
        require __DIR__ . '/../resources/prestashop-modules/webserviceapi/classes/MlabFixedCarrierRates.php';
        $carrier = new Carrier();
        Db::getInstance()->rows = [['price' => '3.90', 'delimiter1' => '0', 'delimiter2' => '5']];
        $inpost = MlabFixedCarrierRates::prices($carrier, 1, 10);
        self::assertSame(3.9, $inpost['price_with_tax']);
        self::assertFalse($inpost['price_varies_by_range']);
        Db::getInstance()->rows = [
            ['price' => '6.10', 'delimiter1' => '0', 'delimiter2' => '1000'],
            ['price' => '7.10', 'delimiter1' => '1000', 'delimiter2' => '5000'],
        ];
        $brt = MlabFixedCarrierRates::prices($carrier, 1, 10);
        self::assertSame(6.1, $brt['price_with_tax']);
        self::assertTrue($brt['price_varies_by_range']);
        self::assertSame(7.1, $brt['price_ranges'][1]['price_with_tax']);
        $carrier->shipping_external = true;
        self::assertNull(MlabFixedCarrierRates::prices($carrier, 1, 10)['price_with_tax']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_shipping_tax_and_handling_are_reflected_in_displayed_rate(): void
    {
        require __DIR__ . '/stubs/fixed-carrier-rates-runtime.php';
        require __DIR__ . '/../resources/prestashop-modules/webserviceapi/classes/MlabFixedCarrierRates.php';
        $carrier = new Carrier();
        $carrier->shipping_handling = true;
        $carrier->tax = 22;
        Configuration::$values['PS_TAX'] = 1;
        Configuration::$values['PS_SHIPPING_HANDLING'] = 1;
        Db::getInstance()->rows = [['price' => '4', 'delimiter1' => '0', 'delimiter2' => '5']];
        self::assertSame(6.1, MlabFixedCarrierRates::prices($carrier, 1, 10)['price_with_tax']);
    }
}
