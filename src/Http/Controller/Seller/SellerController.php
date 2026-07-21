<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller\Seller;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Models\Manufacturer;
use PS\Webservice\Domain\Models\ManufacturerDetail;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\Auth\AuthService;
use PS\Webservice\Service\Auth\AuthServiceInterface;
use PS\Webservice\Service\PS\Mailer;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Service\PS\PrestashopService;
use PS\Webservice\Traits\PaginationTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;


class SellerController
{
    use PaginationTrait;

    protected AuthServiceInterface $authService;
    protected Mailer $mailer;
    protected PrestashopRepository $prestashopRepository;
    protected PrestashopService $prestashopService;
    protected Product $productService;

    const PASSWORD_VALIDATION = '/^(?=.*[0-9])(?=.*[!@#$%^&*])(?=.*[A-Z])(?=.*[a-z]).{8,}$/';

    public function __construct(AuthService $authService, PrestashopService $prestashop, Mailer $mailer, PrestashopRepository $prestashopRepository, Product $productService)
    {
        $this->authService = $authService;
        $this->mailer = $mailer;
        $this->prestashopRepository = $prestashopRepository;
        $this->prestashopService = $prestashop;
        $this->productService = $productService;
    }

    public function register(Request $request): ResponseInterface
    {
        $bodyParams = $this->requireArrayPayload($request->getParsedBody());

        if (!empty($bodyParams['shop_name'])) {
            $name = trim((string) $bodyParams['shop_name']);
        } else {
            $name = trim(((string) ($bodyParams['first_name'] ?? '')) . ' ' . ((string) ($bodyParams['last_name'] ?? '')));
        }

        $bodyParams['name'] = $name;

        try {
            Validator::validate($bodyParams, [
                'first_name' => 'required|max:255',
                'last_name' => 'required|max:255',
                'email' => 'required|email|max:64',
                'password' => 'required|confirmed|min:8|max:64|regex:' . self::PASSWORD_VALIDATION,
                'shop_name' => 'required|max:255',
                'address' => 'required|max:255',
                'avatar' => 'file|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $collection = collect([
                'name' => $bodyParams["name"],
                'email' => $bodyParams["email"],
                'password' => $bodyParams["password"],
                'is_seller' => $bodyParams["is_seller"] ?? false
            ]);

            $data = $collection->only('name', 'email', 'password', 'is_seller');

            if (Manufacturer::query()->where('email', $data->get('email'))->exists()) {
                return response(['error' => 'Validation error: email already exists'], 400);
            }

        } catch (\Throwable $e) {
            Log::error("Validation error: " . $e->getMessage());
            return response(['error' => 'Validation error: ' . $e->getMessage()], 400);
        }

        // se l'utente è già loggato eseguiamo il login automatico
        $token = $request->getHeaderLine('Authorization');
        if (!empty($token)) {
            $signup = $this->authService->signUp($data);
            if ($signup === false) {
                Log::error("Sign up failed");
                return response(['error' => 'Sign up failed'], 400);
            }
        } else {
            // eseguiamo login
            $signup = $this->authService->authenticate($request);
            if($signup === false) {
                Log::error("Authentication failed");
                return response(['error' => 'Authentication failed'], 401);
            }
        }

        $sub = $this->extractCognitoAttribute($signup['access_token'], 'sub');
        if ($sub === null) {
            Log::error('Missing Cognito sub after sign up');
            AwsCognitoClient::deleteUser($bodyParams['email']);
            return response(['error' => 'Sign up failed'], 500);
        }

        $uuid = Uuid::uuid4()->toString();

        //save avatar file in blob if provided
        if (isset($bodyParams['avatar']) && is_array($bodyParams['avatar']) && isset($bodyParams['avatar']['tmp_name'])) {
            $avatarPath = $bodyParams['avatar']['tmp_name'];
            $avatarContent = file_get_contents($avatarPath);
        }

        try {
            $this->mailer->sendSignUpMail($bodyParams['email'], $name);
        } catch (\Throwable $e) {
            Log::error("Failed to send sign up email: " . $e->getMessage());
            return response(['error' => 'Failed to send sign up email'], 500);
        }

        // save user in prestashop database
        try {
            $entity = ManufactureEntity::create(
                [
                    'name' => $name,
                    'email' => $data['email'],
                    'sub' => $sub,
                    'link_rewrite' => slugify($name),
                    'uuid' => $uuid,
                    'avatar' => $avatarContent ?? null,
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'fiscal_code' => $data['fiscal_code'] ?? null,
                    'vat_number' => $data['vat_number'] ?? null,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'country' => $data['country'] ?? null,
                    'zip_code' => $data['zip_code'] ?? null,
                    'phone_number' => $data['phone_number'] ?? null,
                ],
                $this->prestashopService
            );
            $this->prestashopRepository->signupNewManufacturer(
                $entity
            );

            $manufacturer = Manufacturer::query()->where('uuid', $uuid)->first();
            if ($manufacturer !== null) {
                $this->upsertManufacturerDetails((int) $manufacturer->id_manufacturer, $entity->toArray());
            }
        } catch (\Throwable $e) {
            Log::error("Failed to save user in Prestashop database: " . $e->getMessage());
            AwsCognitoClient::deleteUser($bodyParams['email']);
            return response(['error' => 'Failed to save seller profile'], 500);
        }

        return response(
            [
                'access_token' => $signup['access_token'] ?? null,
                'refresh_token' => $signup['refresh_token'] ?? null,
                'seller' => ''
            ]
            ,
            201
        );
    }

    public function confirmToken(Request $request): ResponseInterface
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $token = $payload['token'] ?? null;
        if (empty($token)) {
            return response(["error" => "Invalid token"], 400);
        }

        if ($this->authService->confirmToken((string) $token)) {
            return response(['message' => 'Token confirmed successfully'], 200);
        }

        return response(['error' => 'Invalid or expired token'], 400);
    }

    public function login(Request $request): ResponseInterface
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());

        try {
            Validator::validate($payload, [
                'email' => 'required|email|max:255',
                'password' => 'required|string|min:8|max:64',
            ]);
        } catch (\Throwable $e) {
            return response(['error' => 'Validation error: ' . $e->getMessage()], 400);
        }

        try {
            $userAuth = AwsCognitoClient::setBoolClientSecret()->authenticate($payload['email'], $payload['password']);
            if (!empty($userAuth['error'])) {
                return response(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            $decodedToken = AwsCognitoClient::decodeAccessToken($userAuth['AccessToken']);
            $sub = $decodedToken['sub'] ?? null;
            if ($sub === null) {
                return response(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            $manufacturer = Manufacturer::query()->with('details')->where('email', $payload['email'])->first();
            if ($manufacturer === null) {
                return response(['success' => false, 'message' => 'Seller not found'], 404);
            }

            if ($manufacturer->sub !== $sub) {
                $manufacturer->sub = $sub;
                $manufacturer->save();
            }

            Cache::put($sub . 'refresh_token', $userAuth['RefreshToken'], Carbon::now()->addDays(30));
            Cache::put($sub . 'id_token', $userAuth['IdToken'], Carbon::now()->addDays(30));

            return response([
                'success' => true,
                'message' => 'Seller authenticated',
                'refresh_token' => $userAuth['RefreshToken'],
                'id_token' => $userAuth['IdToken'],
                'access_token' => $userAuth['AccessToken'],
                'seller' => $this->serializeManufacturer($manufacturer->fresh('details')),
            ]);
        } catch (\Throwable $e) {
            Log::error('Seller login failed: ' . $e->getMessage());
            return response(['success' => false, 'message' => 'User not authenticated'], 401);
        }
    }

    public function refresh(Request $request): ResponseInterface
    {
        try {
            $token = $this->authService->check($request);
            $decodedToken = AwsCognitoClient::decodeAccessToken($token);
            $idToken = Cache::get(($decodedToken['sub'] ?? '') . 'id_token');

            return response([
                'success' => true,
                'token' => $token,
                'id_token' => $idToken,
            ]);
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 401);
        }
    }

    public function me(Request $request): ResponseInterface
    {
        try {
            $manufacturer = $this->resolveAuthenticatedManufacturer($request);
            return response([
                'success' => true,
                'data' => $this->serializeManufacturer($manufacturer),
            ]);
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 401);
        }
    }

    public function updateMe(Request $request): ResponseInterface
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());

        try {
            $manufacturer = $this->resolveAuthenticatedManufacturer($request);

            if (isset($payload['shop_name']) && is_string($payload['shop_name']) && trim($payload['shop_name']) !== '') {
                $manufacturer->name = trim($payload['shop_name']);
                $manufacturer->link_rewrite = slugify($manufacturer->name);
            }

            if (isset($payload['email']) && is_string($payload['email']) && trim($payload['email']) !== '') {
                $manufacturer->email = trim($payload['email']);
            }

            $manufacturer->save();
            $this->upsertManufacturerDetails((int) $manufacturer->id_manufacturer, $payload);

            return response([
                'success' => true,
                'data' => $this->serializeManufacturer($manufacturer->fresh('details')),
            ]);
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function dashboardSummary(Request $request): ResponseInterface
    {
        try {
            $manufacturer = $this->resolveAuthenticatedManufacturer($request);
            $filter = ['filter[id_manufacturer]' => '[' . (int) $manufacturer->id_manufacturer . ']'];

            $totalProducts = $this->productService->countProducts($filter + ['filter[active]' => 1]);
            $totalRevenue = $this->prestashopRepository->getTotalRevenue((int) $manufacturer->id_manufacturer);
            $totalAddToCart = $this->prestashopRepository->getTotalAddToCart((int) $manufacturer->id_manufacturer);
            $totalOrders = $this->prestashopRepository->getTotalNumberOfOrders((int) $manufacturer->id_manufacturer);
            // $reviews = $this->prestashopRepository->getManufacturertReviews((int) $manufacturer->id_manufacturer);

            return response([
                'currency' => 'eur',
                'total_revenue' => $totalRevenue,
                'total_orders' => $totalOrders,
                'total_add_to_cart' => $totalAddToCart,
                'active_products' => $totalProducts,
            ]);
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function dashboardProductsMetrics(Request $request): ResponseInterface
    {
        try {
            $manufacturer = $this->resolveAuthenticatedManufacturer($request);
            $pagination = $this->getPaginationParams($request->getQueryParams());
            $products = $this->productService->getProductByManufacture(
                (string) $manufacturer->id_manufacturer,
                null,
                ['limit' => min($pagination['per_page'], 50), 'page' => $pagination['page']]
            );

            $metrics = [
                'total' => $this->productService->countProducts(['filter[id_manufacturer]' => '[' . (int) $manufacturer->id_manufacturer . ']']),
                'active' => 0,
                'inactive' => 0,
                'on_sale' => 0,
            ];

            foreach ($products as $product) {
                $data = $product->toArray();
                if ((int) ($data['active'] ?? 0) === 1) {
                    $metrics['active']++;
                } else {
                    $metrics['inactive']++;
                }

                if (!empty($data['on_sale'])) {
                    $metrics['on_sale']++;
                }
            }

            return response(['success' => true, 'data' => $metrics]);
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function products(Request $request): ResponseInterface
    {
        try {
            $manufacturer = $this->resolveAuthenticatedManufacturer($request);
            $queryParams = $request->getQueryParams();
            $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $categoryId = $payload['category'] ?? $queryParams['category'] ?? null;
            $sort = $payload['sort_by'] ?? $queryParams['sort_by'] ?? 'id_DESC';
            $pagination = $this->getPaginationParams($queryParams + $payload);

            $products = $this->productService->getProductByManufacture(
                (string) $manufacturer->id_manufacturer,
                is_string($categoryId) ? $categoryId : null,
                ['limit' => $pagination['per_page'], 'page' => $pagination['page']],
                $sort
            );

            $totalProducts = $this->productService->countProducts([
                'filter[id_manufacturer]' => '[' . (int) $manufacturer->id_manufacturer . ']'
            ]);

            return response($this->paginatedResponse(
                $products->toArray(),
                $pagination['page'],
                $pagination['per_page'],
                $totalProducts
            ));
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function productDetail(Request $request, mixed $response = null, array $args = []): ResponseInterface
    {
        try {
            $manufacturer = $this->resolveAuthenticatedManufacturer($request);
            $productId = (int) ($args['id'] ?? 0);
            if ($productId <= 0) {
                return response(['success' => false, 'message' => 'Product ID is required'], 400);
            }

            $product = $this->productService->getProductById($productId);
            if ($product === null) {
                return response(['success' => false, 'message' => 'Product not found'], 404);
            }

            $data = $product->withFeatures()->toArray();
            if ((int) ($data['id_manufacturer'] ?? 0) !== (int) $manufacturer->id_manufacturer) {
                return response(['success' => false, 'message' => 'Forbidden'], 403);
            }

            return response(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function updateProduct(Request $request, mixed $response = null, array $args = []): ResponseInterface
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());

        try {
            $manufacturer = $this->resolveAuthenticatedManufacturer($request);
            $productId = (int) ($args['id'] ?? 0);
            if ($productId <= 0) {
                return response(['success' => false, 'message' => 'Product ID is required'], 400);
            }

            $product = $this->productService->getProductById($productId);
            if ($product === null) {
                return response(['success' => false, 'message' => 'Product not found'], 404);
            }

            $productData = $product->toArray();
            if ((int) ($productData['id_manufacturer'] ?? 0) !== (int) $manufacturer->id_manufacturer) {
                return response(['success' => false, 'message' => 'Forbidden'], 403);
            }

            $allowedFields = ['name', 'description', 'description_short', 'meta_title', 'meta_description', 'active'];
            $updateData = array_intersect_key($payload, array_flip($allowedFields));
            if ($updateData === []) {
                return response(['success' => false, 'message' => 'No updatable fields provided'], 400);
            }

            //FIXME: quando eseguiamo il update dobbiamo fare il trigger della ai per creare la descrizione
            $this->productService->updateProduct($productId, $updateData);

            return response(['success' => true, 'message' => 'Product updated']);
        } catch (\Throwable $e) {
            return response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function deleteProduct(Request $request, mixed $response = null, array $args = []): ResponseInterface
    {
        return response(['success' => false, 'message' => 'Seller product deletion is not implemented yet'], 501);
    }

    public function updateProductDiscount(Request $request, mixed $response = null, array $args = []): ResponseInterface
    {
        return response(['success' => false, 'message' => 'Seller product discount update is not implemented yet'], 501);
    }

    public function deleteProductDiscount(Request $request, mixed $response = null, array $args = []): ResponseInterface
    {
        return response(['success' => false, 'message' => 'Seller product discount deletion is not implemented yet'], 501);
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function requireArrayPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        return $payload;
    }

    private function resolveAuthenticatedManufacturer(Request $request): Manufacturer
    {
        $accessToken = $this->authService->check($request);
        $decodedToken = AwsCognitoClient::decodeAccessToken($accessToken);
        $sub = $decodedToken['sub'] ?? null;

        if (!is_string($sub) || $sub === '') {
            throw new \RuntimeException('Invalid access token', 401);
        }

        $manufacturer = Manufacturer::query()->with('details')->where('sub', $sub)->first();
        if ($manufacturer !== null) {
            return $manufacturer;
        }

        $idToken = Cache::get($sub . 'id_token');
        if (is_string($idToken) && $idToken !== '') {
            $decodedIdToken = AwsCognitoClient::decodeAccessToken($idToken);
            $email = $decodedIdToken['email'] ?? null;
            if (is_string($email) && $email !== '') {
                $manufacturer = Manufacturer::query()->with('details')->where('email', $email)->first();
                if ($manufacturer !== null) {
                    $manufacturer->sub = $sub;
                    $manufacturer->save();
                    return $manufacturer->fresh('details');
                }
            }
        }

        throw new \RuntimeException('Seller not found', 404);
    }

    private function upsertManufacturerDetails(int $manufacturerId, array $payload): void
    {
        $detailFields = [
            'first_name',
            'last_name',
            'fiscal_code',
            'vat_number',
            'address',
            'city',
            'state',
            'country',
            'zip_code',
            'phone_number',
            'avatar',
        ];

        $details = [];
        foreach ($detailFields as $field) {
            if (array_key_exists($field, $payload)) {
                $details[$field] = $payload[$field];
            }
        }

        if ($details === []) {
            return;
        }

        ManufacturerDetail::query()->updateOrCreate(
            ['id_manufacturer' => $manufacturerId],
            $details
        );
    }

    private function extractCognitoAttribute(string $accessToken, string $attributeName): ?string
    {
        $attributes = AwsCognitoClient::decodeAccessToken($accessToken);
        if (isset($attributes[$attributeName])) {
            return $attributes[$attributeName];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeManufacturer(Manufacturer $manufacturer): array
    {
        $data = $manufacturer->toArray();
        $data['shop_name'] = $data['name'] ?? null;

        return $data;
    }
}