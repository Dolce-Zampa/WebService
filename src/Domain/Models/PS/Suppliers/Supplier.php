<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Suppliers;

use Illuminate\Database\Eloquent\Relations\HasOne;
use PS\Webservice\Domain\Models\PS\PsTable;
use PS\Webservice\Domain\Models\PS\Suppliers\SupplierLang;
use PS\Webservice\Domain\Models\PS\Suppliers\SupplierShop;
use PS\Webservice\Domain\Models\PS\Address;

class Supplier extends PsTable
{
	protected $table = 'supplier';
	protected $primaryKey = 'id_supplier';
	public $timestamps = false;
	protected $guarded = [];

	public function lang(): HasOne
	{
		return $this->hasOne(SupplierLang::class, 'id_supplier', 'id_supplier');
	}

	public function shop(): HasOne
	{
		return $this->hasOne(SupplierShop::class, 'id_supplier', 'id_supplier');
	}

	public function address(): HasOne
	{
		return $this->hasOne(Address::class, 'id_supplier', 'id_supplier');
	}

}