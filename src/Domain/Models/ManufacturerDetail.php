<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

class ManufacturerDetail extends PsTable
{
    protected $table = 'manufacturer_details';
    protected $primaryKey = 'id_manufacturer';
    public $timestamps = false;
    protected $guarded = [];
}