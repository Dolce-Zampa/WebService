<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Manufacturers;

use PS\Webservice\Domain\Models\PS\Manufacturers\ManufacturerDetail;
use PS\Webservice\Domain\Models\PS\Manufacturers\ManufacturerLang;
use PS\Webservice\Domain\Models\PS\Manufacturers\ManufacturerShop;
use PS\Webservice\Domain\Models\PS\PsTable;
use PS\Webservice\Domain\Models\PS\Address;

class Manufacturer extends PsTable
{
	protected $table = 'manufacturer';
	protected $primaryKey = 'id_manufacturer';
	public $timestamps = false;
	protected $guarded = [];

	public function details()
	{
		return $this->hasOne(ManufacturerDetail::class, 'id_manufacturer', 'id_manufacturer');
	}

	public function lang()
	{
		return $this->hasOne(ManufacturerLang::class, 'id_manufacturer', 'id_manufacturer');
	}

	public function shop()
	{
		return $this->hasOne(ManufacturerShop::class, 'id_manufacturer', 'id_manufacturer');
	}

	public function address()
	{
		return $this->hasOne(Address::class, 'id_manufacturer', 'id_manufacturer');
	}

}