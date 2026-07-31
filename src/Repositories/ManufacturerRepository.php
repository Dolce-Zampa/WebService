<?php
declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Carbon\Carbon;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Models\Manufacturer;
use PS\Webservice\Domain\Models\ManufacturerDetail;
use PS\Webservice\Domain\Models\ManufacturerLang;
use PS\Webservice\Domain\Models\ManufacturerShop;
use Ramsey\Uuid\Uuid;

class ManufacturerRepository
{
    protected \Illuminate\Database\Capsule\Manager $db;

    public function signupNewManufacturer(ManufactureEntity $manufacture): void
    {
        $manufacturer = Manufacturer::updateOrCreate(
            ['email' => $manufacture->email],
            [
                'name' => $manufacture->name,
                'uuid' => Uuid::uuid4()->toString(),
                'active' => 0,
                'link_rewrite' => slugify($manufacture->name),
                'date_add' => Carbon::now(),
                'date_upd' => Carbon::now(),
                'sub' => $manufacture->sub,
            ]
        );

        ManufacturerDetail::updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            [
                'id_manufacturer' => $manufacturer->id_manufacturer,
                'first_name' => $manufacture->first_name,
                'last_name' => $manufacture->last_name,
                'fiscal_code' => $manufacture->fiscal_code,
                'vat_number' => $manufacture->vat_number,
                'address' => $manufacture->address,
                'city' => $manufacture->city,
                'zip_code' => $manufacture->postcode,
                'country' => $manufacture->country,
                'state' => $manufacture->state,
                'phone_number' => $manufacture->phone_number,
                'avatar' => $manufacture->avatar
            ]
        );

        ManufacturerShop::updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            [
                'id_manufacturer' => $manufacturer->id_manufacturer,
                'id_shop' => 1,
            ]
        );

        ManufacturerLang::updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            [
                'id_manufacturer' => $manufacturer->id_manufacturer,
                'id_lang' => 1,
                'description' => $manufacture->description,
                'short_description' => $manufacture->short_description,
                'meta_title' => $manufacture->meta_title,
                'meta_description' => $manufacture->meta_description,
            ]
        );
    }

    public function getTotalAddToCart(int $idManufacturer): int
    {
        $results = $this->db->table('v_manufacturer_cart_products_stats')->where('id_manufacturer', $idManufacturer)->get();
        $count = 0;
        foreach ($results as $row) {
            $count += (int) $row->total_quantity_added_to_cart;
        }

        return $count;
    }

    public function getTotalRevenue(int $idManufacturer): float
    {
        $results = $this->db->table('v_manufacturer_sales_stats')->where('id_manufacturer', $idManufacturer)->get();
        return $results->first()->total_revenue_tax_incl ?? 0.0;
    }

    public function getTotalNumberOfOrders(int $idManufacturer): int
    {
        $results = $this->db->table('v_manufacturer_orders_stats')->where('id_manufacturer', $idManufacturer)->get();
        return $results->first()->total_orders ?? 0;
    }

}