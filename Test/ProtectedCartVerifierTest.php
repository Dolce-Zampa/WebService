<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\PurchaseProtection\ProtectedCartVerifier;
use PS\Webservice\Service\PurchaseProtection\ProtectionQuote;

final class ProtectedCartVerifierTest extends TestCase
{
    private function carts(): array
    {
        $before = ['id' => 42, 'id_carrier' => 3, 'currency_iso' => 'EUR',
            'products' => [['id_product' => 10, 'quantity' => 1, 'price_wt' => 80]],
            'totals' => ['grand_total_tax_incl' => 86.9, 'shipping_tax_incl' => 6.9]];
        $after = $before;
        $after['products'][] = ['id_product' => 999, 'quantity' => 1, 'price_wt' => 4, 'is_virtual' => true];
        $after['totals']['grand_total_tax_incl'] = 90.9;
        return [$before, $after];
    }

    public function testAcceptsExactlyOneVirtualServiceWithoutChangingTheGoods(): void
    {
        [$before, $after] = $this->carts();
        ProtectedCartVerifier::verify($before, $after, ProtectionQuote::calculate(8000, 500), 999);
        self::assertTrue(true);
    }

    /** @dataProvider invalidChanges */
    public function testRejectsUnexpectedCartChanges(callable $change): void
    {
        [$before, $after] = $this->carts();
        $change($after);
        $this->expectException(InvalidArgumentException::class);
        ProtectedCartVerifier::verify($before, $after, ProtectionQuote::calculate(8000, 500), 999);
    }

    public static function invalidChanges(): array
    {
        return [
            'coupon affects fee' => [static function (&$cart) { $cart['totals']['grand_total_tax_incl'] = 90.50; }],
            'shipping changed' => [static function (&$cart) { $cart['totals']['shipping_tax_incl'] = 7.90; }],
            'goods changed' => [static function (&$cart) { $cart['products'][0]['quantity'] = 2; }],
            'fee is physical' => [static function (&$cart) { $cart['products'][1]['is_virtual'] = false; }],
            'duplicate fee' => [static function (&$cart) { $cart['products'][] = $cart['products'][1]; }],
            'another cart' => [static function (&$cart) { $cart['id'] = 43; }],
        ];
    }
}
