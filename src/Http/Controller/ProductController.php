<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

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

    public function productByCategory(Request $request, Response $response)
    {
        $category = $request->getQueryParams()['category'] ?? null;

        if (!$category) {
            return response([
                'success' => false,
                'message' => 'Category query parameter is required'
            ], 400);
        }

        $pagination = $this->getPaginationParams($request->getQueryParams());
        $categoryId = (int) $category;
        
        $totalProducts = $this->productService->countProducts(['filter[id_category_default]' => $categoryId]);
        $productsByCategory = $this->productService->getProductByCategory($categoryId, [
            'limit' => $pagination['per_page'],
            'page' => $pagination['page']
        ]);
        
        $response = $this->paginatedResponse(
            $productsByCategory->toArray(),
            $pagination['page'],
            $pagination['per_page'],
            $totalProducts
        );

        // build filers
        $response['filters'] = $this->productService->buildFiltersProducts($categoryId);
        return response([
            'products' => $response['data'],
            'pagination' => $response['pagination'],
        ]);
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

        return response($productDetail->toArray());
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
}