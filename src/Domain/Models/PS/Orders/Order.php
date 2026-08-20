<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Orders;

use PS\Webservice\Domain\Models\PS\PsTable;

class Order extends PsTable
{
    protected $table = 'orders';
    protected $primaryKey = 'id_order';

}