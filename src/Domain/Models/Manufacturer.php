<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

use PS\Webservice\Domain\Models\ManufacturerDetail;

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

}