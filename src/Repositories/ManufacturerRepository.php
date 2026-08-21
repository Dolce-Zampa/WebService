<?php
declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;
use PS\Webservice\Domain\Models\PS\Suppliers\Supplier;
use Ramsey\Uuid\Uuid;

class ManufacturerRepository extends PrestashopRepository implements RepositoryInterface
{
    protected \Illuminate\Database\Capsule\Manager $db;

    /**
     * Registra un nuovo manufacturer con tutte le sue relazioni
     *
     * @param ManufactureEntity $manufacture
     * @return Manufacturer
     * @throws \Exception
     */
    public function signupNewManufacturer(ManufactureEntity $manufacture): Manufacturer
    {
        try {
            return DB::transaction(function () use ($manufacture) {
                // 1. Crea o aggiorna il manufacturer
                $manufacturer = $this->createOrUpdateManufacturer($manufacture);
                
                // 2. Crea o aggiorna i dettagli del manufacturer
                $this->createOrUpdateManufacturerDetails($manufacturer, $manufacture);
                
                // 3. Crea o aggiorna la relazione shop
                $this->createOrUpdateManufacturerShop($manufacturer);
                
                // 4. Crea o aggiorna la relazione lang
                $this->createOrUpdateManufacturerLang($manufacturer, $manufacture);
                
                // 5. Crea o aggiorna il supplier associato
                $this->saveSupplierWithRelations($manufacture);
                
                // 6. Restituisce il manufacturer con le relazioni caricate
                return $manufacturer->load(['details', 'shop', 'lang']);
            });
        } catch (\Exception $e) {
            Log::error('Errore durante la registrazione del manufacturer: ' . $e->getMessage(), [
                'email' => $manufacture->email,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Crea o aggiorna il manufacturer principale
     *
     * @param ManufactureEntity $manufacture
     * @return Manufacturer
     */
    private function createOrUpdateManufacturer(ManufactureEntity $manufacture): Manufacturer
    {
        $now = Carbon::now();
        
        return Manufacturer::updateOrCreate(
            ['email' => $manufacture->email],
            [
                'name' => $manufacture->name,
                'uuid' => Uuid::uuid4()->toString(),
                'active' => 0,
                'link_rewrite' => slugify($manufacture->name),
                'date_add' => $now,
                'date_upd' => $now,
                'sub' => $manufacture->sub,
                'premium' => $manufacture->premium
            ]
        );
    }

    /**
     * Crea o aggiorna i dettagli del manufacturer
     *
     * @param Manufacturer $manufacturer
     * @param ManufactureEntity $manufacture
     * @return void
     */
    private function createOrUpdateManufacturerDetails(Manufacturer $manufacturer, ManufactureEntity $manufacture): void
    {
        $manufacturer->details()->updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            [
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
    }

    /**
     * Crea o aggiorna la relazione shop del manufacturer
     *
     * @param Manufacturer $manufacturer
     * @return void
     */
    private function createOrUpdateManufacturerShop(Manufacturer $manufacturer): void
    {
        $manufacturer->shop()->updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            ['id_shop' => 1]
        );
    }

    /**
     * Crea o aggiorna la relazione lang del manufacturer
     *
     * @param Manufacturer $manufacturer
     * @param ManufactureEntity $manufacture
     * @return void
     */
    private function createOrUpdateManufacturerLang(Manufacturer $manufacturer, ManufactureEntity $manufacture): void
    {
        $manufacturer->lang()->updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            [
                'id_lang' => 1,
                'description' => $manufacture->description,
                'short_description' => $manufacture->short_description,
                'meta_title' => $manufacture->meta_title,
                'meta_description' => $manufacture->meta_description,
            ]
        );
    }

    /**
     * Crea o aggiorna il supplier associato al manufacturer
     *
     * @param Manufacturer $manufacturer
     * @param ManufactureEntity $manufacture
     * @return void
     */
    private function createOrUpdateSupplier(Manufacturer $manufacturer, ManufactureEntity $manufacture): void
    {
        $now = Carbon::now();
        
        Supplier::updateOrCreate(
            ['id_supplier' => $manufacturer->id_manufacturer],
            [
                'name' => $manufacture->name,
                'active' => 0,
                'date_add' => $now,
                'date_upd' => $now,
            ]
        );
    }

    /**
     * Summary of createOrUpdateSupplierAddress
     * @param Supplier $supplier
     * @param ManufactureEntity $manufacture
     * @return void
     */
    private function createOrUpdateSupplierAddress(Supplier $supplier, ManufactureEntity $manufacture): void
    {
        $supplier->address()->updateOrCreate(
            ['id_supplier' => $supplier->id_supplier],
            [
                'address1' => $manufacture->address,
                'city' => $manufacture->city,
                'postcode' => $manufacture->postcode,
                'id_country' => $manufacture->id_country ?? 11,
                'dni' => $manufacture->fiscal_code,
                'phone_mobile' => $manufacture->phone_number,
                'vat_number' => $manufacture->vat_number,
                'iban' => $manufacture->iban,
                'alias' => 'default',
                'lastname' => $manufacture->lastname,
                'firstname' => $manufacture->firstname,
                'date_add' => Carbon::now(),
                'date_upd' => Carbon::now(),
            ]
        );
    }

    /**
     * Salva il supplier con le sue relazioni
     *
     * @param ManufactureEntity $manufacturer
     * @return Supplier
     * @throws \Exception
     */
    public function saveSupplierWithRelations(ManufactureEntity $manufacturer): Supplier
    {
        try {
            return DB::transaction(function () use ($manufacturer) {
                $now = Carbon::now();
                
                // 1. Salva il supplier principale
                $supplier = Supplier::updateOrCreate(
                    ['id_supplier' => $manufacturer->id_manufacturer],
                    [
                        'name' => $manufacturer->name,
                        'active' => 0,
                        'date_add' => $now,
                        'date_upd' => $now,
                    ]
                );

                // 2. Salva la relazione lang se ci sono dati
                $this->createOrUpdateSupplierLang($supplier, ['id_lang' => 1]);

                // 3. Salva la relazione shop se ci sono dati
                $this->createOrUpdateSupplierShop($supplier, ['id_shop' => 1]);

                $this->createOrUpdateSupplierAddress($supplier, $manufacturer);

                // 4. Restituisce il supplier con le relazioni caricate
                return $supplier->load(['lang', 'shop', 'address']);
            });
        } catch (\Exception $e) {
            Log::error('Errore durante il salvataggio del supplier con relazioni: ' . $e->getMessage(), [
                'id_supplier' => $manufacturer->id_manufacturer,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Crea o aggiorna la relazione lang del supplier
     *
     * @param Supplier $supplier
     * @param array $langData
     * @return void
     */
    private function createOrUpdateSupplierLang(Supplier $supplier, array $langData): void
    {
        $supplier->lang()->updateOrCreate(
            ['id_supplier' => $supplier->id_supplier],
            $langData
        );
    }

    /**
     * Crea o aggiorna la relazione shop del supplier
     *
     * @param Supplier $supplier
     * @param array $shopData
     * @return void
     */
    private function createOrUpdateSupplierShop(Supplier $supplier, array $shopData): void
    {
        $supplier->shop()->updateOrCreate(
            ['id_supplier' => $supplier->id_supplier],
            $shopData
        );
    }

    /**
     * Ottiene il totale degli articoli aggiunti al carrello per un manufacturer
     *
     * @param int $idManufacturer
     * @return int
     */
    public function getTotalAddToCart(int $idManufacturer): int
    {
        $results = $this->db->table('v_manufacturer_cart_products_stats')
            ->where('id_manufacturer', $idManufacturer)
            ->get();
        
        $count = 0;
        foreach ($results as $row) {
            $count += (int) $row->total_quantity_added_to_cart;
        }

        return $count;
    }

    /**
     * Ottiene il totale degli articoli aggiunti al carrello per un manufacturer
     *
     * @param int $idManufacturer
     * @return int
     */
    public function getProductAddToCart(int $idManufacturer): Collection
    {
        $results = $this->db->table('v_manufacturer_cart_products_stats')
            ->where('id_manufacturer', $idManufacturer)
            ->get();

        return $results;
    }

    /**
     * Ottiene il ricavo totale per un manufacturer
     *
     * @param int $idManufacturer
     * @return float
     */
    public function getTotalRevenue(int $idManufacturer): float
    {
        $results = $this->db->table('v_manufacturer_sales_stats')
            ->where('id_manufacturer', $idManufacturer)
            ->get();
        
        return (float) $results->first()->total_revenue_tax_incl ?? 0.0;
    }

    /**
     * Ottiene il numero totale di ordini per un manufacturer
     *
     * @param int $idManufacturer
     * @return int
     */
    public function getTotalNumberOfOrders(int $idManufacturer): int
    {
        $results = $this->db->table('v_manufacturer_sales_stats')
            ->where('id_manufacturer', $idManufacturer)
            ->get();
        
        return (int) $results->first()->total_orders ?? 0;
    }
}