<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Orders;

use Illuminate\Database\Eloquent\Model;

class OrderReviewMailLog extends Model
{
    protected $table = 'order_review_mail_logs';

    protected $fillable = [
        'id_order',
        'id_customer',
        'email',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'id_order', 'id_order');
    }
}
