<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class ProductReviews extends Model
{
    protected $table = 'product_reviews';
    protected $fillable = ['id_product', 'id_customer','id_order', 'rating', 'comment', 'status'];

}
    