<?php
declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Illuminate\Support\Collection;

class PrestashopRepository extends ManufacturerRepository
{
    protected \Illuminate\Database\Capsule\Manager $db;

    public function __construct(\Illuminate\Database\Capsule\Manager $db)
    {
        $this->db = $db;
    }

    /**
     * retrive all product reviews for a given product
     * @param int $idProduct
     * @return Collection<int, \stdClass>
     */
    public function getProductReviews(int $idProduct): Collection
    {
        $reviews = $this->db->table('product_reviews')
            ->where('id_product', $idProduct)
            ->get();

        return $reviews;
    }

     /**
     * retrive all product reviews for a given product
     * @param int $idmanufacturer
     * @return Collection<int, \stdClass>
     */
    public function getManufacturertReviews(int $idmanufacturer): Collection
    {
        $reviews = $this->db->table('product_reviews')
            ->where('id_manufacturer', $idmanufacturer)
            ->get();

        return $reviews;
    }

    public function findUserIdFromSub(string $sub): ?int
    {
        $user = $this->db->table('customer')
            ->where('sub', $sub)
            ->first();

        return $user ? (int) $user->id_customer : null;
    }
}