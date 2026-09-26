<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Entities\CartRuleEntity;
use PS\Webservice\Domain\Object\Filter;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Facades\JsonDataStorage;
use PS\Webservice\Repositories\PrestashopRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PS\Webservice\Service\PS\Cart;

class CartController extends Controller {
    protected Cart $cartService;
    protected ?PrestashopRepository $prestashopRepository;

    public function __construct(Cart $cartService, ?PrestashopRepository $prestashopRepository = null)
    {
        $this->cartService = $cartService;
        $this->prestashopRepository = $prestashopRepository;
    }

    public function getCartList(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        $authenticatedCustomerId = $this->resolveAuthenticatedCustomerIdOrDeny($request);
        if ($authenticatedCustomerId instanceof Response) {
            return $authenticatedCustomerId;
        }

        if ($authenticatedCustomerId !== $customerId) {
            return response(['error' => 'Forbidden'], 403);
        }

        $cartList = $this->cartService->getCartListFromUserId((string) $authenticatedCustomerId);
        
        if(is_null($cartList)) {
            return response([], 404);
        }

        return response($cartList);

    }

    public function getCart(Request $request, Response $response, array $argv): Response
    {
        $cartId = (int) $argv['cartId'];
        $ownerContext = $this->resolveOwnerContext($request, $request->getQueryParams());
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }

        $cart = $this->cartService->getCartFromId($cartId, $ownerContext['customerId'], $ownerContext['guestId']);
        if(is_null($cart)) {
            return response([], 404);
        }

        return response($cart->toArray());

    }

    public function updateCart(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $cartId = $argv['cartId'];
        $ownerContext = $this->resolveOwnerContext($request, $payload);
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }

        $operation = isset( $payload['op']) ? (string) $payload['op'] : 'up';

        $cart = $this->cartService->getCartFromId($cartId, $ownerContext['customerId'], $ownerContext['guestId']);
        if (is_null($cart)) {
            return response([], 404);
        }

        if ($ownerContext['customerId'] !== null) {
            $payload['customerId'] = $ownerContext['customerId'];
            $payload['id_customer'] = $ownerContext['customerId'];
            unset($payload['id_guest'], $payload['guestId'], $payload['isGuest'], $payload['is_guest']);
        }

        $cart = $this->cartService->updateCart(
            $payload,
            $cartId,
            $ownerContext['customerId'] ?? $ownerContext['guestId'],
            $ownerContext['guestId'] !== null,
            $operation
        );
        
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
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $ownerContext = $this->resolveOwnerContext($request, $payload);
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }

        if ($ownerContext['customerId'] !== null) {
            $payload['customerId'] = $ownerContext['customerId'];
            $payload['id_customer'] = $ownerContext['customerId'];
            unset($payload['id_guest'], $payload['guestId'], $payload['isGuest'], $payload['is_guest']);
        } else {
            $payload['id_guest'] = $ownerContext['guestId'];
        }

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
        $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $ownerContext = $this->resolveOwnerContext($request, $payload);
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }

        $cart = $this->cartService->getCartFromId($cartId, $ownerContext['customerId'], $ownerContext['guestId']);
        if (is_null($cart)) {
            return response([], 404);
        }

        $result = $this->cartService->deleteCart($cartId, $ownerContext['customerId'], $ownerContext['guestId']);
        
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
        $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $ownerContext = $this->resolveOwnerContext($request, $payload);
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }

        $cart = $this->cartService->getCartFromId($cartId, $ownerContext['customerId'], $ownerContext['guestId']);
        if (is_null($cart)) {
            return response([], 404);
        }

        $isValid = $this->cartService->validateCoupon(
            $code,
            $cartId,
            $ownerContext['customerId'] !== null ? (string) $ownerContext['customerId'] : null,
            $ownerContext['guestId'] !== null ? (string) $ownerContext['guestId'] : null
        );
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

    /**
     * Upselling cart with a new product
     * @param Request $request
     * @return void
     */
    public function upsellingCart(Request $request): Response
    {
        $payload = $request->getQueryParams();
        $idManufacturer = $payload['manufacturer'];
        $pagination = [
            'page' => 1,
            'limit' => 3
        ];
        $missingAmount = 20; // $payload['missing_amount'];

        // get product of the specified manufacturer that can fulfill the missing amount
        $filter = new Filter([
            'price' => ['gte' => $missingAmount],
        ]);
        $productList = $this->cartService->getProductByManufacture($idManufacturer, null, $pagination, 'price_ASC', $filter);
        if($productList->count() === 0) {
            return response(['products' => []]);
        }

        return response(['products' => $productList->toArray()]);

    }

    protected function requireArrayPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        return $payload;
    }

    protected function resolveAuthenticatedCustomerId(Request $request): int
    {
        $sub = $request->getAttribute('user_id');
        if (!is_string($sub) || $sub === '') {
            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader === '') {
                throw new \RuntimeException('Unauthorized', 401);
            }

            if (strpos($authHeader, 'Bearer ') !== 0) {
                throw new \RuntimeException('Unauthorized', 401);
            }

            $authToken = substr($authHeader, 7);
            if ($authToken === '') {
                throw new \RuntimeException('Unauthorized', 401);
            }

            try {
                $decodedToken = AwsCognitoClient::decodeAccessToken($authToken);
            } catch (\Throwable) {
                throw new \RuntimeException('Unauthorized', 401);
            }

            $sub = $decodedToken['sub'] ?? null;
            if (!is_string($sub) || $sub === '') {
                throw new \RuntimeException('Unauthorized', 401);
            }
        }

        $customerId = $this->prestashopRepository?->findUserIdFromSub($sub);
        if (!is_int($customerId) || $customerId <= 0) {
            throw new \RuntimeException('Forbidden', 403);
        }

        return $customerId;
    }

    protected function resolveAuthenticatedCustomerIdOrDeny(Request $request): int|Response
    {
        try {
            return $this->resolveAuthenticatedCustomerId($request);
        } catch (\Throwable $e) {
            $status = (int) $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 401;
            }

            return response(['error' => $e->getMessage()], $status);
        }
    }

    protected function resolveOwnerContext(Request $request, array $payload): array|Response
    {
        try {
            return [
                'customerId' => $this->resolveAuthenticatedCustomerId($request),
                'guestId' => null,
            ];
        } catch (\Throwable $e) {
            $guestId = $payload['id_guest'] ?? $payload['guestId'] ?? null;
            $isGuest = (bool) ($payload['isGuest'] ?? $payload['is_guest'] ?? false);
            if (($guestId === null || $guestId === '') && $isGuest === true && isset($payload['customerId'])) {
                $guestId = $payload['customerId'];
            }

            $hasAuthenticatedPrincipal = (is_string($request->getAttribute('user_id')) && $request->getAttribute('user_id') !== '')
                || strpos($request->getHeaderLine('Authorization'), 'Bearer ') === 0;

            if ($guestId !== null && $guestId !== '' && !$hasAuthenticatedPrincipal) {
                return [
                    'customerId' => null,
                    'guestId' => $guestId,
                ];
            }

            if ((int) $e->getCode() === 403) {
                $status = (int) $e->getCode();
                if ($status < 400 || $status > 599) {
                    $status = 401;
                }

                return response(['error' => $e->getMessage()], $status);
            }

            foreach (['id_customer', 'customerId'] as $customerKey) {
                if (isset($payload[$customerKey]) && $payload[$customerKey] !== '') {
                    return response(['error' => 'Unauthorized'], 401);
                }
            }

            $status = (int) $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 401;
            }

            return response(['error' => $e->getMessage()], $status);
        }
    }

}
