<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Manufacturers;

use PS\Webservice\Domain\Models\PS\PsTable;

class Customer extends PsTable
{
    protected $table = 'customer';
    protected $primaryKey = 'id_customer';

}