<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Suppliers;

use PS\Webservice\Domain\Models\PS\PsTable;

class SupplierLang extends PsTable
{
    protected $table = 'supplier_lang';
    protected $primaryKey = 'id_supplier';
    public $timestamps = false;
    protected $guarded = [];
}