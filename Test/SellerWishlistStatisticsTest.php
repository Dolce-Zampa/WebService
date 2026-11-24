<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Seller\WishlistStatistics;

final class SellerWishlistStatisticsTest extends TestCase
{
    public function test_counts_distinct_customers_and_only_the_authenticated_sellers_products(): void
    {
        $manager = new Manager();
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'ps_']);
        $db = $manager->getConnection();
        $db->statement('CREATE TABLE ps_configuration (id_configuration INTEGER, name TEXT, value TEXT)');
        $db->statement('CREATE TABLE ps_product (id_product INTEGER PRIMARY KEY, id_manufacturer INTEGER, reference TEXT)');
        $db->statement('CREATE TABLE ps_product_lang (id_product INTEGER, id_lang INTEGER, id_shop INTEGER, name TEXT)');
        $db->statement('CREATE TABLE ps_dz_wishlist_item (customer_id INTEGER, product_id INTEGER, product_attribute_id INTEGER)');
        $db->table('configuration')->insert([['id_configuration' => 1, 'name' => 'PS_LANG_DEFAULT', 'value' => '2'], ['id_configuration' => 2, 'name' => 'PS_SHOP_DEFAULT', 'value' => '1']]);
        $db->table('product')->insert([['id_product' => 1, 'id_manufacturer' => 10, 'reference' => 'A'], ['id_product' => 2, 'id_manufacturer' => 10, 'reference' => 'B'], ['id_product' => 3, 'id_manufacturer' => 20, 'reference' => 'C']]);
        $db->table('product_lang')->insert([['id_product' => 1, 'id_lang' => 2, 'id_shop' => 1, 'name' => 'Prodotto A'], ['id_product' => 1, 'id_lang' => 1, 'id_shop' => 1, 'name' => 'Other language'], ['id_product' => 2, 'id_lang' => 2, 'id_shop' => 1, 'name' => 'Prodotto B'], ['id_product' => 3, 'id_lang' => 2, 'id_shop' => 1, 'name' => 'Altro venditore']]);
        $db->table('dz_wishlist_item')->insert([['customer_id' => 1, 'product_id' => 1, 'product_attribute_id' => 0], ['customer_id' => 1, 'product_id' => 1, 'product_attribute_id' => 3], ['customer_id' => 2, 'product_id' => 1, 'product_attribute_id' => 0], ['customer_id' => 1, 'product_id' => 2, 'product_attribute_id' => 0], ['customer_id' => 2, 'product_id' => 3, 'product_attribute_id' => 0]]);
        $service = new WishlistStatistics($db);
        $first = $service->forSeller(10, 1, 1);
        self::assertSame(2, $first['products_count']);
        self::assertSame(3, $first['saves_count']);
        self::assertSame([['id_product' => 1, 'name' => 'Prodotto A', 'reference' => 'A', 'customers_count' => 2]], $first['items']);
        self::assertSame(2, $service->forSeller(10, 2, 1)['items'][0]['id_product']);
        self::assertSame(3, $service->forSeller(20)['items'][0]['id_product']);
        self::assertSame(0, $service->forSeller(30)['products_count']);
        $db->table('dz_wishlist_item')->where('customer_id', 2)->where('product_id', 1)->delete();
        self::assertSame(2, $service->forSeller(10)['saves_count']);
    }
}
