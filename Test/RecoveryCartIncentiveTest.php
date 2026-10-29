<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Service\Promotions\RecoveryCartIncentive;
use PS\Webservice\Service\PS\Order;

final class RecoveryCartIncentiveTest extends TestCase
{
    private Manager $db;
    private RecoveryCartIncentive $incentive;

    protected function setUp(): void
    {
        $this->db = new Manager();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->schema()->dropIfExists('manufacturer_details');
        $this->db->schema()->create('manufacturer_details', function ($table): void {
            $table->integer('id_manufacturer');
            $table->string('commission');
        });
        $this->db->table('manufacturer_details')->insert(['id_manufacturer' => 7, 'commission' => '20.00']);
        $this->incentive = new RecoveryCartIncentive($this->db);
    }

    public function test_shipping_coupon_only_when_commission_covers_shipping(): void
    {
        self::assertStringContainsString('ORD3RFREE', $this->offer(100, 20));
        self::assertSame('', $this->offer(100, 20.01));
    }

    public function test_free_shipping_uses_five_percent_when_commission_covers_it(): void
    {
        self::assertStringContainsString('5ORD3R', $this->offer(100, 0));
        $this->db->table('manufacturer_details')->update(['commission' => '5.00']);
        self::assertSame('', $this->offer(100, 0));
    }

    public function test_missing_seller_and_existing_discounts_fail_closed(): void
    {
        self::assertSame('', $this->offer(100, 0, 8));
        self::assertSame('', $this->offer(100, 0, 7, [['code' => 'OTHER']]));
    }

    public function test_multiple_sellers_use_their_own_rates(): void
    {
        $this->db->table('manufacturer_details')->insert(['id_manufacturer' => 8, 'commission' => '10.00']);
        $cart = ['currency_iso' => 'EUR', 'cart_rules' => [], 'totals' => ['shipping_tax_incl' => 15], 'products' => [
            ['id_product' => 1, 'name' => 'A', 'id_manufacturer' => 7, 'quantity' => 1, 'price_wt' => 50],
            ['id_product' => 2, 'name' => 'B', 'id_manufacturer' => 8, 'quantity' => 1, 'price_wt' => 50],
        ]];
        $service = $this->createMock(Order::class);
        $session = OrderSession::create(['cart_id' => 1, 'id_carrier' => 1, 'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Example', 'delivery_address' => ['address1' => 'Via Roma 1', 'city' => 'Roma', 'postcode' => '00100', 'country' => 'IT']], $service)], $service);
        foreach ($cart['products'] as $product) $session->addCartLineItem($product);
        $session->addCartShipping($cart);
        self::assertStringContainsString('ORD3RFREE', $this->incentive->message($cart, $session));
        $this->db->table('manufacturer_details')->where('id_manufacturer', 8)->update(['commission' => '9.00']);
        self::assertSame('', $this->incentive->message($cart, $session));
    }

    private function offer(float $price, float $shipping, int $seller = 7, array $rules = []): string
    {
        $cart = ['currency_iso' => 'EUR', 'cart_rules' => $rules, 'totals' => ['shipping_tax_incl' => $shipping], 'products' => [
            ['id_product' => 1, 'name' => 'Prodotto', 'id_manufacturer' => $seller, 'quantity' => 1, 'price_wt' => $price],
        ]];
        $service = $this->createMock(Order::class);
        $session = OrderSession::create(['cart_id' => 1, 'id_carrier' => 1, 'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Example', 'delivery_address' => ['address1' => 'Via Roma 1', 'city' => 'Roma', 'postcode' => '00100', 'country' => 'IT']], $service)], $service);
        $session->addCartLineItem($cart['products'][0]);
        $session->addCartShipping($cart);
        return $this->incentive->message($cart, $session);
    }
}
