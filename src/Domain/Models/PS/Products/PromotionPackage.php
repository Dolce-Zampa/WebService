<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Products;

use Illuminate\Database\Eloquent\Relations\HasMany;
use PS\Webservice\Domain\Models\PS\PsTable;

class PromotionPackage extends PsTable
{
    protected $table = 'promotion_packages';
    protected $fillable = ['name', 'duration_days', 'price', 'position', 'active'];

    public function promotions(): HasMany
    {
        return $this->hasMany(ProductPromotion::class, 'package_id', 'id');
    }
}
