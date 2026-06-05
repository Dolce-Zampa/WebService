<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Domain\Object\Filter;
use PS\Webservice\Http\Controller\Controller;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Traits\PaginationTrait;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductController extends Controller
{
    use PaginationTrait;

    private Product $productService;

    public function __construct(Product $productService)
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
        Filter $filter
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
}