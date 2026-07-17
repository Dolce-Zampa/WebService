<?php
declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Carbon\Carbon;
use PS\Webservice\Domain\Entities\ManufactureEntity;

class ManufacturerRepository
{
    protected string $tablePrefix;
    protected \Illuminate\Database\Capsule\Manager $db;

    public function signupNewManufacturer(ManufactureEntity $manufacture): void
    {
        $this->db->table($this->tablePrefix . 'manufacturer')
            ->insert([
                'uuid' => $manufacture->uuid,
                'email' => $manufacture->email,
                'sub' => $manufacture->sub,
                'name' => $manufacture->name,
                'date_add' => Carbon::now()->toDateTimeString(),
                'date_upd' => Carbon::now()->toDateTimeString(),
                'active' => 0,
                'link_rewrite' => $manufacture->link_rewrite,
            ]);
    }

    public function getTotalAddToCart(int $idManufacturer): int
    {
        $query = '
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
        WHERE p.`id_manufacturer` = :idManufacturer
        GROUP BY p.`id_product`, pl.`name`, p.`reference`, p.`id_manufacturer`, m.`name`
        ORDER BY total_quantity_added_to_cart DESC;
        ';

        $results = $this->db->query($query, [$idManufacturer]);

        $count = 0;
        foreach ($results as $row) {
            $count += (int) $row->total_quantity_added_to_cart;
        }

        return $count;
    }

    public function getTotalRevenue(int $idManufacturer): float
    {
        $query = '
        SELECT 
            m.`id_manufacturer`,
            m.`name` AS manufacturer_name,
            COUNT(DISTINCT od.`id_order`) AS total_orders,
            SUM(od.`product_quantity`) AS total_units_sold,
            ROUND(SUM(od.`total_price_tax_incl`), 2) AS total_revenue_tax_incl,
            ROUND(SUM(od.`total_price_tax_excl`), 2) AS total_revenue_tax_excl,
            ROUND(AVG(od.`total_price_tax_incl`), 2) AS avg_order_value
        FROM `fy8ie_order_detail` od
        LEFT JOIN `fy8ie_product` p ON od.`product_id` = p.`id_product`
        LEFT JOIN `fy8ie_manufacturer` m ON p.`id_manufacturer` = m.`id_manufacturer`
        WHERE p.`id_manufacturer` = :idManufacturer
        GROUP BY m.`id_manufacturer`, m.`name`
        ORDER BY total_revenue_tax_incl DESC;
        ';

        $results = $this->db->query($query, [$idManufacturer]);

        return $results->first()->total_revenue_tax_incl ?? 0.0;
    }

    public function getTotalNumberOfOrders(int $idManufacturer): int
    {
        $query = '
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
        WHERE m.`id_manufacturer` = :idManufacturer
        GROUP BY m.`id_manufacturer`, m.`name`;
        ';

        $results = $this->db->query($query, [$idManufacturer]);

        return $results->first()->total_orders ?? 0;
    }

}