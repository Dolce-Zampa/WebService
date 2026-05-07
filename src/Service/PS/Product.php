<?php
declare(strict_types=1);

namespace DolzeZampa\WS\Service\PS;

use DolzeZampa\WS\Domain\Entities\ProductEntity;
use DolzeZampa\WS\Domain\Models\ProductLangTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class Product extends PrestashopService implements PrestashopServiceInterface {

    public function countProducts(array $filter = []): int
    {
        $queryString = http_build_query(['display' => '[id]'] + $filter);
        $this->httpService->setUrl("/products?{$queryString}");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve products: " . $response->getHttpCode());
        }

        return count($response->toArray()['products']);
    }

    /**
     * Retrieves a list of products.
     * //TODO: va impagginato
     *
     * @return Collection The collection of product entities.
     */
    public function productsList(array $displayOptions = ['display' => 'full']): Collection {

        if(!empty($displayOptions)) {
            $queryString = http_build_query($displayOptions);
            $this->httpService->setUrl("/products?{$queryString}");
        } else {
            $this->httpService->setUrl("/products");
        }

        Log::debug("Fetching product list with options: " . json_encode($displayOptions));

        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve products: " . $response->getHttpCode());
        }

        $collection = new Collection();
        foreach($response->toArray()['products'] as $productData) {
            $collection->push(ProductEntity::create($productData, $this));
        }

        return $collection;
    }
    /**
     * Retrieves featured products.
     *
     * @return Collection The collection of featured product entities.
     */
    public function getFeaturedProducts(): Collection {

        $products = $this->productsList(['display' => 'full','sort' => 'id_DESC', 'limit' => 4]);
        return $products;
    }

        /**
         * Retrieves a collection of products belonging to a specific category.
         *
         * @param int $categoryId The ID of the category to retrieve products from
         * @return Collection A collection of products that belong to the specified category
         */
        public function getProductByCategory(int $categoryId, array $pagination = []): Collection {
         $limit = $pagination['limit'] ?? 10;
         $page = $pagination['page'] ?? 1;
         $offset = ($page - 1) * $limit;

        $products = $this->productsList(['display' => 'full','sort' => 'id_DESC', 'limit' => "$offset,$limit", 'filter[id_category_default]' => $categoryId, 'filter[active]' => 1]);
        return $products;
    }

    /**
     * Retrieves detailed information about a product based on its slug.
     *
     * @param string $slug The unique identifier slug of the product to retrieve
     *
     * @return ProductEntity|null The product entity containing detailed information,
     *                            or null if the product is not found
     */
    public function getProductDetail(string $slug): ?ProductEntity {

        //first we nee to get the product id from the slug, then we can get the product detail with the id
        $productId = ProductLangTable::where('link_rewrite', $slug)->value('id_product');
        if (!$productId) {
            return null; // Product not found
        }

        $this->httpService->setUrl("/products/{$productId}?display=full");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            if ($response->getHttpCode() === 404) {
                return null; // Product not found
            }
            throw new \RuntimeException("Failed to retrieve product detail: " . $response->getHttpCode());
        }

        $productData = $response->toArray()['products'][0];
        $product = ProductEntity::create($productData, $this);
        return $product;
    }
}