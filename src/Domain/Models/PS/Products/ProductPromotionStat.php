<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Products;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PS\Webservice\Domain\Models\PS\PsTable;

class ProductPromotionStat extends PsTable
{
    protected $table = 'product_promotion_stats';
    protected $fillable = ['promotion_id', 'impressions', 'clicks'];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(ProductPromotion::class, 'promotion_id', 'id');
    }
}
