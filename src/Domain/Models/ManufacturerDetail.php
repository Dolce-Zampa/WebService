<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

class ManufacturerDetail extends PsTable
{
    protected $table = 'manufacturer_details';
    public $timestamps = false;
    protected $guarded = [];

	public static function getAvatar(int $idManufacturer): ?string
	{
		$manufacturer = self::where('id_manufacturer',$idManufacturer)->first();
		if ($manufacturer) {
			return $manufacturer->avatar;
		}
		return null;
	}
}