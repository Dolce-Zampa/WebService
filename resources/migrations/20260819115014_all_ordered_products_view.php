<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AllOrderedProductsView extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $this->execute('DROP VIEW IF EXISTS `fy8ie_v_manufacturer_sales_stats`;');
        $this->execute('CREATE VIEW `fy8ie_v_manufacturer_sales_stats` AS
                SELECT 
                    p.`id_product`,
                    p.`price`,
                    pl.`name` AS product_name,
                    p.`reference`,
                    p.`id_manufacturer`,
                    m.`name` AS manufacturer_name,
                    SUM(cp.`quantity`) AS total_quantity_added_to_cart,
                    COUNT(DISTINCT cp.`id_cart`) AS number_of_carts,
                    COUNT(DISTINCT od.`id_order`) AS total_orders
                FROM `fy8ie_product` p
                LEFT JOIN `fy8ie_product_lang` pl ON p.`id_product` = pl.`id_product`
                LEFT JOIN `fy8ie_manufacturer` m ON p.`id_manufacturer` = m.`id_manufacturer`
                LEFT JOIN `fy8ie_cart_product` cp ON p.`id_product` = cp.`id_product`
                LEFT JOIN `fy8ie_order_detail` od ON p.`id_product` = od.`product_id`
                LEFT JOIN `fy8ie_orders` o ON od.`id_order` = o.`id_order` AND o.`valid` = 1
                GROUP BY 
                    p.`id_product`, 
                    p.`price`, 
                    pl.`name`, 
                    p.`reference`, 
                    p.`id_manufacturer`, 
                    m.`name`;');
    }
}
