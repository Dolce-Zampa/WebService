<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\PS\Customer;
use PS\Webservice\Domain\Models\PS\Orders\Order;
use PS\Webservice\Domain\Models\PS\Products\Product;
use PS\Webservice\Domain\Models\PS\Products\ProductReviews;
use PS\Webservice\Domain\Object\Filter;
use PS\Webservice\Http\Controller\Controller;
use PS\Webservice\Service\PS\Product as ProductService;
use PS\Webservice\Traits\PaginationTrait;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductController extends Controller
{
    use PaginationTrait;

    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function productList(Request $request, Response $response)
    {
        $pagination = $this->getPaginationParams($request->getQueryParams());
        $totalProducts = $this->productService->countProducts();

        $productList = $this->productService->productsList([
            'display' => 'full',
            'sort' => 'id_DESC',
            'limit' => $pagination['per_page'],
            'page' => $pagination['page']
        ]);

        $response = $this->paginatedResponse(
            $productList->toArray(),
            $pagination['page'],
            $pagination['per_page'],
            $totalProducts
        );
    }

    public function featuredProducts(Request $request, Response $response)
    {
        $featuredProducts = $this->productService->getFeaturedProducts();


        return response([
            'success' => true,
            'data' => $featuredProducts->toArray()
        ]);
    }

    /**
     * Retrive a category page products
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function productByCategory(Request $request, Response $response)
    {
        $queryParams = $request->getQueryParams();

        $category = $queryParams['category'] ?? null;
        $manufacturer = $queryParams['manufacturer'] ?? null;
        $filters = $queryParams['filters'] ?? [];

        if (!$category && !$manufacturer) {
            return response([
                'success' => false,
                'message' => 'Category or Manufacturer query parameter is required'
            ], 400);
        }
        $pagination = $this->getPaginationParams($queryParams);

        //fix provvisoria perchè non riusciamo a recuperare i prodotti se sono filtrati
        if($filters && is_array($filters) && count($filters) > 0){
            $pagination['per_page'] = 30; // Set a high limit to retrieve all products
        }

        $paginationOptions = [
            'limit' => $pagination['per_page'],
            'page' => $pagination['page'],
        ];
        $filter = new Filter($filters);
        $sort = $queryParams['sort_by'] ?? 'id_DESC';

        [$products, $countParam] = $this->resolveProductsAndCountParam(
            $category,
            $manufacturer,
            $paginationOptions,
            $sort,
            $filter
        );

        $totalProducts = $this->productService->countProducts($countParam);
        $paginatedData = $this->paginatedResponse(
            $products->toArray(),
            $pagination['page'],
            $pagination['per_page'],
            $totalProducts
        );

        $firstCategory = (int) (explode('|', $category ?? '')[0] ?? 0);
        $paginatedData['filters'] = $this->productService->buildFiltersProducts($firstCategory)?->toArray();

        return response([
            'products' => $paginatedData['data'],
            'pagination' => $paginatedData['pagination'],
            'filters' => $paginatedData['filters'],
        ]);
    }

    private function resolveProductsAndCountParam(
        ?string $category,
        ?string $manufacturer,
        array $paginationOptions,
        string $sort = 'id_DESC',
        ?Filter $filter = null
    ): array {
        if ($manufacturer === null) {
            return [
                $this->productService->getProductByCategory($category, $paginationOptions, $sort, $filter),
                ['filter[id_category_default]' => "[$category]"],
            ];
        }

        return [
            $this->productService->getProductByManufacture($manufacturer, $category, $paginationOptions, $sort, $filter),
            ['filter[id_manufacturer]' => "[$manufacturer]"]
        ];
    }

    public function productDetail(Request $request, Response $response, array $args)
    {
        $slug = $args['slug'] ?? null;
        if (!$slug) {
            return response([
                'success' => false,
                'message' => 'Product slug is required'
            ], 400);
        }

        $productDetail = $this->productService->getProductDetail($slug);
        if (!$productDetail) {
            return response([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        return response($productDetail->withFeatures()->toArray());
    }

    public function productById(Request $request, Response $response, array $args)
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if (!$id || $id <= 0) {
            return response([
                'success' => false,
                'message' => 'Product ID is required'
            ], 400);
        }

        $productDetail = $this->productService->getProductById($id);
        if (!$productDetail) {
            return response([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        return response($productDetail->withFeatures()->toArray());
    }

    public function productsRelated(Request $request, Response $response, array $args)
    {
        $id = $args['id'] ?? null;
        if (!$id) {
            return response([
                'success' => false,
                'message' => 'Product ID is required'
            ], 400);
        }

        // $relatedProducts = $this->productService->getRelatedProducts((int) $id);
        return response([
            'success' => true,
            'data' => []
        ]);
    }

    public function searchProducts(Request $request, Response $response)
    {
        $query = $request->getQueryParams()['q'] ?? null;
        if (!$query) {
            return response([
                'success' => false,
                'message' => 'Search query parameter "q" is required'
            ], 400);
        }

        $searchResults = $this->productService->searchProducts($query);
        return response($searchResults->toArray());
    }

    public function featuredPromotions(Request $request, Response $response)
    {
        $promotions = $this->productService->getFeaturedPromotions();

        return response([
            'success' => true,
            'data' => $promotions->toArray()
        ]);
    }

    public function addProductReview(Request $request, Response $response, array $args)
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if (!$id || $id <= 0) {
            return response([
                'success' => false,
                'message' => 'Product ID is required'
            ], 400);
        }

        $data = json_decode((string) $request->getBody(), true);
        if (!$data || !isset($data['review']) || !isset($data['rating']) || empty($data['order_id']) || $this->checkForExpolitCode($data['review'])) {
            return response([
                'success' => false,
                'message' => 'All data are required'
            ], 400);
        }

        $review = $data['review'];
        $rating = (int) $data['rating'];
        $orderId = $data['order_id'];

        // first check if the order exists and belongs to the customer
        $order = Order::where('reference', $orderId)->first();
        $product = Product::where('id_product', $id)->firstOrFail(); // Ensure the product exists

        if(!$order) {
            return response([
                'success' => false,
                'message' => 'Invalid order or customer'
            ], 400);
        }
       
        // save the review using the product service
        ProductReviews::create([
            'id_product' => $id,
            'id_customer' => $order ? $order->id_customer : null,
            'id_order' => $order ? $order->id_order : null,
            'id_manufacturer' => $product->manufacturer_id ?? null,
            'comment' => $review,
            'rating' => $rating
        ]);

        return response([
            'success' => true,
            'message' => 'Review added successfully'
        ]);
    }

    private function checkForExpolitCode(string $data): bool
    {
        // Check for common exploit patterns
        $patterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is', // Script tags
            '/<\?php\b[^>]*>(.*?)<\/\?php>/is',   // PHP tags
            '/(union|select|insert|update|delete|drop|alter)\s+/i', // SQL keywords
            '/(eval|base64_decode|exec|shell_exec|system)\s*\(/i', // Dangerous functions
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true; // Exploit code found
            }
        }

        return false; // No exploit code found
    }

    public function getAllProductReviews(Request $request, Response $response)
    {
        $queryParams = $request->getQueryParams();
        $pagination = $this->getPaginationParams($queryParams);

        if($queryParams['limit'] !== null) {
            $reviews = ProductReviews::query()
                ->limit((int) $queryParams['limit'])
                ->get();
        } else {
            $reviews = ProductReviews::all();
        }

        //build all product from cache
        $reviewCompleteData = [];
        foreach($reviews as $review) {
            try {
                $product = ProductEntity::create(['id' => $review->id_product], null);
                $reviewCompleteData[] = [
                    'id' => $review->id_product_review,
                    'id_product' => $review->id_product,
                    'product_name' => $product->name,
                    'comment' => $review->comment,
                    'rating' => $review->rating,
                    'created_at' => $review->date_add,
                    'id_manufacturer' => $review->id_manufacturer,
                ];
            } catch (\Exception $e) {
                Log::warning("Failed to build review data for review ID {$review->id_product_review}: " . $e->getMessage());
                continue; // Skip this review and continue with the next one
            }
        }

        return response([
            'success' => true,
            'data' => $reviewCompleteData,
        ]);
    }
}