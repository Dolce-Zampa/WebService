<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Products;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PS\Webservice\Domain\Models\PS\PsTable;

class ProductPromotion extends PsTable
{
    protected $table = 'product_promotions';

    protected $fillable = [
        'product_id',
        'seller_id',
        'package_id',
        'price',
        'start_date',
        'end_date',
        'status',
        'stripe_session_id',
        'stripe_payment_intent_id',
    ];

    protected $casts = [
        'price' => 'float',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(PromotionPackage::class, 'package_id', 'id');
    }

    public function stats(): HasOne
    {
        return $this->hasOne(ProductPromotionStat::class, 'promotion_id', 'id');
    }
}
