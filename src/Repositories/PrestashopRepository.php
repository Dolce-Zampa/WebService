<?php
declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Illuminate\Database\Eloquent\Collection;
use PS\Webservice\Domain\Entities\ReviewEntity;

class PrestashopRepository
{
    protected \Illuminate\Database\Capsule\Manager $db;

    public function __construct(\Illuminate\Database\Capsule\Manager $db)
    {
        $this->db = $db;
    }

    /**
     * retrive all product reviews for a given product
     * @param int $idProduct
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    public function getProductReviews(int $idProduct): Collection
    {
        $reviews = $this->db->table('product_reviews')
            ->where('id_product', $idProduct)
            ->get();

        return $reviews;
    }
}