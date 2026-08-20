<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Products;

use PS\Webservice\Domain\Models\PS\PsTable;

class Product extends PsTable
{
    protected $table = 'product';
    protected $primaryKey = 'id_product';

}