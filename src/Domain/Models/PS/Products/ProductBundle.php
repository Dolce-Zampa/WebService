<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Products;

use PS\Webservice\Domain\Models\PS\PsTable;

final class ProductBundle extends PsTable
{
    protected $table = 'product_bundles';

    public $timestamps = true;

    protected $fillable = [
        'parent_product_id',
        'bundled_product_id',
        'reduction',
        'reduction_type',
        'active_reduction',
        'position',
    ];

    protected $casts = [
        'parent_product_id' => 'integer',
        'bundled_product_id' => 'integer',
        'reduction' => 'float',
        'active_reduction' => 'boolean',
        'position' => 'integer',
    ];
}
