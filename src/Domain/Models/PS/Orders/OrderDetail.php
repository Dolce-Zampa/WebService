<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Orders;

use PS\Webservice\Domain\Models\PS\Products\Product;
use PS\Webservice\Domain\Models\PS\PsTable;

class OrderDetail extends PsTable
{
    protected $table = 'order_detail';
    protected $primaryKey = 'id_order_detail';

    public function order()
    {
        return $this->belongsTo(Order::class, 'id_order', 'id_order');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id_product');
    }
}
