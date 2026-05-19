<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Entities\CartRuleEntity;
use PS\Webservice\Facades\JsonDataStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PS\Webservice\Service\PS\Cart;

class CartController extends Controller {
    protected Cart $cartService;

    public function __construct(Cart $cartService)
    {
        $this->cartService = $cartService;
    }

    public function getCartList(Request $request, Response $response, array $argv): Response
    {
        $customerId = $argv['customerId'];
        $cartList = $this->cartService->getCartListFromUserId($customerId);
        
        if(is_null($cartList)) {
            return response([], 404);
        }

        return response($cartList);

    }

    public function getCart(Request $request, Response $response, array $argv): Response
    {
        $cartId = $argv['cartId'];
        $queryParams = $request->getQueryParams();
        $customerId = isset($queryParams['customer_id']) ? $queryParams['customer_id'] : null;
        $guestId = isset($queryParams['guest_id']) ? $queryParams['guest_id'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required to access cart'], 403);
        }

        $cart = $this->cartService->getCartFromId($cartId, $customerId, $guestId);
        
        if(is_null($cart)) {
            return response([], 404);
        }

        return response($cart->toArray());

    }

    public function updateCart(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        $cartId = $argv['cartId'];
        $isGuest = (bool) $payload['isGuest'] ?? false;
        $customerId = $payload['customerId'];
        $operation = isset( $payload['op']) ? (string) $payload['op'] : 'up';

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        //find customerId from cookie session
        $cart = $this->cartService->updateCart($payload, $cartId, $customerId, $isGuest, $operation);
        
        if($cart->failed()) {
            return response([
                "error" => "Failed to create cart",
            ], 500);
        }

        $cartEntity = CartEntity::create($cart->toArray()['data']['cart'], $this->cartService);
        return response($cartEntity->toArray(), 201);
    }

    public function createCart(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        //find customerId from cookie session
        $cart = $this->cartService->newCart($payload);

        if($cart->failed()) {
            return response([
                "error" => "Failed to create cart",
            ], 500);
        }

        $cartEntity = CartEntity::create($cart->toArray()['data']['cart'], $this->cartService);
        return response($cartEntity->toArray(), 201);
    }

    public function deleteCart(Request $request, Response $response, array $argv): Response
    {
        $cartId = $argv['cartId'];
        $cartId = $argv['cartId'];
        $params = $request->getParsedBody();
        $payload = $request->getParsedBody();
        $isGuest = (bool) $params['isGuest'] ?? false;
        $customerId = empty($isGuest) ? $payload['customerId'] : null;
        $guestId = $isGuest == true ? $payload['customerId'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required to delete cart'], 403);
        }

        $result = $this->cartService->deleteCart($cartId, $customerId, $guestId);
        
        if($result->failed()) {
            return response([
                "error" => "Failed to delete cart",
            ], 500);
        }

        return response(['message' => 'Cart deleted successfully']);
    }

    public function getFeaturedCoupons(Request $request, Response $response, array $argv): Response
    {
        $storage = JsonDataStorage::coupon()->fetchAll();
        return response($storage);
    }

    public function getCouponDetail(Request $request, Response $response, array $argv): Response
    {
        $code = (string) ($argv['code'] ?? '');
        $coupon = $this->cartService->getCouponDetail($code);

        if ($coupon === null) {
            return response(['error' => 'Coupon not found'], 404);
        }

        return response($coupon->toArray());
    }

    public function validateCoupon(Request $request, Response $response, array $argv): Response
    {
        $code = (string) ($argv['code'] ?? '');
        $cartId = (string) ($argv['cartId'] ?? '');
        $params = $request->getParsedBody();
        $customerId = isset($params['customer_id']) ? (string) $params['customer_id'] : null;
        $guestId = isset($params['guest_id']) ? (string) $params['guest_id'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Either customer_id or guest_id must be provided as a query parameter'], 400);
        }

        $isValid = $this->cartService->validateCoupon($code, $cartId, $customerId, $guestId);
        return response($isValid);
    }

    public function getCartRules(Request $request, Response $response, array $argv): Response
    {
        $cartRuleSettings = file_get_contents(__DIR__ . '/../../../storage/configs/cart_rules.json');
        $cartRules = CartRuleEntity::create(json_decode($cartRuleSettings, true), $this->cartService);
        return response($cartRules->toArray());
    }

    protected function validateCartPayload(array $payload): bool
    {
        $requiredFields = [
            'id_customer',
            'id_currency',
            'id_lang',
            'id_carrier',
            'replace_products',
            'id_address_delivery',
            'id_address_invoice',
            'products',
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new \InvalidArgumentException("Missing required field: {$field}", 400);
            }
        }

        $intFields = [
            'id_customer',
            'id_currency',
            'id_lang',
            'id_carrier',
            'id_address_delivery',
            'id_address_invoice',
        ];

        foreach ($intFields as $field) {
            if (!is_int($payload[$field]) || $payload[$field] <= 0) {
                throw new \InvalidArgumentException("Field {$field} must be a positive integer", 400);
            }
        }

        if (!is_bool($payload['replace_products'])) {
            throw new \InvalidArgumentException('Field replace_products must be a boolean', 400);
        }

        if (!is_array($payload['products']) || count($payload['products']) === 0) {
            throw new \InvalidArgumentException('Field products must be a non-empty array', 400);
        }

        foreach ($payload['products'] as $index => $product) {
            if (!is_array($product)) {
                throw new \InvalidArgumentException("Product at index {$index} must be an object", 400);
            }

            $requiredProductFields = ['id_product', 'id_product_attribute', 'quantity'];
            foreach ($requiredProductFields as $productField) {
                if (!array_key_exists($productField, $product)) {
                    throw new \InvalidArgumentException("Missing required product field {$productField} at index {$index}", 400);
                }
            }

            if (!is_int($product['id_product']) || $product['id_product'] <= 0) {
                throw new \InvalidArgumentException("Field id_product at index {$index} must be a positive integer", 400);
            }

            if (!is_int($product['id_product_attribute']) || $product['id_product_attribute'] < 0) {
                throw new \InvalidArgumentException("Field id_product_attribute at index {$index} must be an integer >= 0", 400);
            }

            if (!is_int($product['quantity']) || $product['quantity'] <= 0) {
                throw new \InvalidArgumentException("Field quantity at index {$index} must be a positive integer", 400);
            }
        }

        return true;
    }

}
