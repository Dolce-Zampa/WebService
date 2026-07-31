<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

class ManufacturerShop extends PsTable
{
    protected $table = 'manufacturer_shop';
    protected $primaryKey = 'id_manufacturer';
    public $timestamps = false;
    protected $guarded = [];
}