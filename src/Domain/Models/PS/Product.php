<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS;

use PS\Webservice\Domain\Models\PS\PsTable;

class Product extends PsTable
{
    protected $table = 'products';
    protected $primaryKey = 'id_product';

}