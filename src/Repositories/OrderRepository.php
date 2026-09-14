<?php
declare(strict_types=1);

namespace PS\Webservice\Repositories;


class OrderRepository extends PrestashopRepository implements RepositoryInterface
{

    public function getProductFromAbbandonedCart(string $customerMail, int $cartId): array
    {
        $query = "SELECT
                ac.id_abandoned_cart,
                ac.id_cart as cart_id,
                cp.id_product,
                cp.id_product_attribute,
                cp.quantity
            FROM fy8ie_abandoned_cart ac
            JOIN fy8ie_cart_product cp
                ON cp.id_cart = ac.id_cart
            WHERE ac.email = ? AND ac.id_cart = ?;";

        return $this->db->select($query, [$customerMail, $cartId]);
    }
}