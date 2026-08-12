<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

use PS\Webservice\Domain\Models\PS\PsTable;


final class ProductLangTable extends PsTable
{
    protected $table = 'product_lang';
    protected $primaryKey = 'id_product';

}