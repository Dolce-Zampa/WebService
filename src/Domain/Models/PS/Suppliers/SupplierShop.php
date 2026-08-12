<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Suppliers;

use PS\Webservice\Domain\Models\PS\PsTable;

class SupplierShop extends PsTable
{
    protected $table = 'supplier_shop';
    protected $primaryKey = 'id_supplier';
    public $timestamps = false;
    protected $guarded = [];
}