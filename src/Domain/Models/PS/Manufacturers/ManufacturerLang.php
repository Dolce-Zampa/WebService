<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Manufacturers;

use PS\Webservice\Domain\Models\PS\PsTable;

class ManufacturerLang extends PsTable
{
    protected $table = 'manufacturer_lang';
    protected $primaryKey = 'id_manufacturer';
    public $timestamps = false;
    protected $guarded = [];
}