<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Orders;

use PS\Webservice\Domain\Models\PS\Customer;
use PS\Webservice\Domain\Models\PS\PsTable;

class Order extends PsTable
{
    protected $table = 'orders';
    protected $primaryKey = 'id_order';

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'id_customer', 'id_customer');
    }

    public function details()
    {
        return $this->hasMany(OrderDetail::class, 'id_order', 'id_order');
    }

    public function reviewMailLog()
    {
        return $this->hasOne(OrderReviewMailLog::class, 'id_order', 'id_order');
    }

    public function scopeDelivered($query, int $stateId = 5)
    {
        return $query->where('current_state', $stateId);
    }

    public function scopePendingReviewMail($query)
    {
        return $query->whereDoesntHave('reviewMailLog');
    }
}