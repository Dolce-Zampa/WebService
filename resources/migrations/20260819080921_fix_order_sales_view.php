<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixOrderSalesView extends AbstractMigration
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
                GROUP BY m.`id_manufacturer`, m.`name`;');
    }
}
