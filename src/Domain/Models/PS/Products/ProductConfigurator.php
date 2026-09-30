<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Products;

use PS\Webservice\Domain\Models\PS\PsTable;

class ProductConfigurator extends PsTable
{
    protected $table = 'webserviceapi_configurator';
    protected $primaryKey = 'id_product';

    protected $fillable = [
        'id_product',
        'name',
        'price',
    ];

}