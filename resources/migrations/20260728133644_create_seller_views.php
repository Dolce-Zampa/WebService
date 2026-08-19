<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSellerViews extends AbstractMigration
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
    public function up(): void
    {
        $views = [
            'CREATE VIEW `fy8ie_v_manufacturer_sales_stats` AS
                SELECT 
                    m.`id_manufacturer`,
                    m.`name` AS manufacturer_name,
                    COUNT(DISTINCT od.`id_order`) AS total_orders,
                    SUM(od.`product_quantity`) AS total_units_sold,
                    ROUND(SUM(od.`total_price_tax_incl`), 2) AS total_revenue_tax_incl,
                    ROUND(SUM(od.`total_price_tax_excl`), 2) AS total_revenue_tax_excl,
                    ROUND(AVG(od.`total_price_tax_incl`), 2) AS avg_order_value
                FROM `fy8ie_order_detail` od
                LEFT JOIN `fy8ie_orders` o ON od.`id_order` = o.`id_order`
                LEFT JOIN `fy8ie_product` p ON od.`product_id` = p.`id_product`
                LEFT JOIN `fy8ie_manufacturer` m ON p.`id_manufacturer` = m.`id_manufacturer`
                WHERE o.`valid` = 1
                GROUP BY m.`id_manufacturer`, m.`name`;',

                'CREATE VIEW `fy8ie_v_manufacturer_orders_stats` AS
                SELECT 
                    m.`id_manufacturer`,
                    m.`name` AS manufacturer_name,
                    COUNT(DISTINCT o.`id_order`) AS total_orders,
                    ROUND(SUM(o.`total_paid_tax_incl`), 2) AS total_revenue,
                    ROUND(AVG(o.`total_paid_tax_incl`), 2) AS avg_order_value
                FROM `fy8ie_orders` o
                INNER JOIN `fy8ie_order_detail` od ON o.`id_order` = od.`id_order`
                INNER JOIN `fy8ie_product` p ON od.`product_id` = p.`id_product`
                INNER JOIN `fy8ie_manufacturer` m ON p.`id_manufacturer` = m.`id_manufacturer`
                GROUP BY m.`id_manufacturer`, m.`name`;',

                'CREATE VIEW `fy8ie_v_manufacturer_cart_products_stats` AS
                SELECT 
                    p.`id_product`,
                    pl.`name` AS product_name,
                    p.`reference`,
                    p.`id_manufacturer`,
                    m.`name` AS manufacturer_name,
                    SUM(cp.`quantity`) AS total_quantity_added_to_cart,
                    COUNT(DISTINCT cp.`id_cart`) AS number_of_carts
                FROM `fy8ie_cart_product` cp
                LEFT JOIN `fy8ie_product` p ON cp.`id_product` = p.`id_product`
                LEFT JOIN `fy8ie_product_lang` pl ON p.`id_product` = pl.`id_product`
                LEFT JOIN `fy8ie_manufacturer` m ON p.`id_manufacturer` = m.`id_manufacturer`
                GROUP BY p.`id_product`, pl.`name`, p.`reference`, p.`id_manufacturer`, m.`name`;'
        ];

        foreach ($views as $view) {
            $this->execute($view);
        }
    }

    public function down(): void
    {
        $views = [
            'fy8ie_v_manufacturer_sales_stats',
            'fy8ie_v_manufacturer_orders_stats',
            'fy8ie_v_manufacturer_cart_products_stats'
        ];
        foreach ($views as $view) {
            $this->execute("DROP VIEW IF EXISTS `$view`;");
        }
    }
}
