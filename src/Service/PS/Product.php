<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Entities\FilterEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Object\Filter;

class Product extends PrestashopService implements PrestashopServiceInterface
{

    public function countProducts(array $filter = []): int
    {
        $queryString = http_build_query(['display' => '[id]'] + $filter);
        $this->httpService->setUrl("/products?{$queryString}");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new PrestashopConnectorException($this->httpService);
        }

        if(empty($response->toArray())) {
            return 0; // No products found
        }

        $products = $response->toArray()['products'] ?? [];
        return count($products);
    }

    /**
     * Retrieves a list of products.
     * //TODO: va impagginato
     *
     * @return Collection The collection of product entities.
     */
    public function productsList(array $displayOptions = ['display' => 'full'], ?Filter $filter = null): Collection
    {

        if (!empty($displayOptions)) {
            $queryString = http_build_query($displayOptions);
            $this->httpService->setUrl("/products?{$queryString}&price[original_price][use_tax]=1&price[original_price][use_reduction]=1");
        } else {
            $this->httpService->setUrl("/products");
        }

        Log::debug("Fetching product list with options: " . json_encode($displayOptions));

        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new PrestashopConnectorException($this->httpService);
        }

        $collection = new Collection();
        $products = $response->toArray()['products'] ?? [];
        foreach ($products as $productData) {
            if(!is_null($filter) && $filter->match($productData) !== true) {
                continue; // Skip products that do not match the filter criteria
            }
            $collection->push(ProductEntity::create($filter->productData, $this));
        }

        return $collection;
    }
    /**
     * Retrieves featured products.
     *
     * @return Collection The collection of featured product entities.
     */
    public function getFeaturedProducts(): Collection
    {

        $products = $this->productsList(['display' => 'full', 'sort' => 'id_DESC', 'limit' => 4]);
        return $products;
    }

    /**
     * Retrieves a collection of products belonging to a specific category.
     *
     * @param string $categoryId The ID of the category to retrieve products from
     * @return Collection A collection of products that belong to the specified category
     */
    public function getProductByCategory(string $categoryId, array $pagination = [], string $sort = 'id_DESC', ?Filter $filters = null): Collection
    {
        $limit = $pagination['limit'] ?? 10;
        $page = $pagination['page'] ?? 1;
        $offset = ($page - 1) * $limit;

        $products = $this->productsList(['display' => 'full', 'sort' => $sort, 'limit' => "$offset,$limit", 'filter[id_category_default]' => "[$categoryId]", 'filter[active]' => 1]
        , $filters);
        return $products;
    }

    /**
     * Retrieves a collection of products belonging to a specific category.
     *
     * @param string $categoryId The ID of the category to retrieve products from
     * @return Collection A collection of products that belong to the specified category
     */
    public function getProductByManufacture(string $manufactureId, string $categoryId = null, array $pagination = [], string $sort = 'id_DESC', ?Filter $filters = null): Collection
    {
        $limit = $pagination['limit'] ?? 10;
        $page = $pagination['page'] ?? 1;
        $offset = ($page - 1) * $limit;

        $options = ['display' => 'full', 'sort' => $sort, 'limit' => "$offset,$limit", 'filter[id_manufacturer]' => "[$manufactureId]", 'filter[active]' => 1];
        if (!empty($categoryId)) {
            $options['filter[id_category_default]'] = "[$categoryId]";
        }

        $products = $this->productsList($options, $filters);
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
    public function getProductDetail(string $slug): ?ProductEntity
    {

        //first we nee to get the product id from the slug, then we can get the product detail with the id
        $productId = $this->findProductIdBySlug($slug);
        if (!$productId) {
            return null; // Product not found
        }

        return $this->getProductById($productId);
        
    }

    public function getProductById(int $id): ?ProductEntity
    {
        $this->httpService->setUrl("/products/{$id}?price[original_price][use_tax]=1&price[original_price][use_reduction]=1&display=full");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            if ($response->getHttpCode() === 404) {
                return null; // Product not found
            }
            throw new PrestashopConnectorException($this->httpService);
        }

        $productData = $response->toArray()['products'][0];
        $product = ProductEntity::create($productData, $this);
        return $product;
    }

    public function buildFiltersProducts(int $categoryId): ?FilterEntity
    {
        $this->httpService->setUrl("/filters?id_category={$categoryId}&ws_key={$this->httpService->getConfig()->apikey}");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            return null; // Failed to retrieve filters for the category
        }

        if (empty($response->toArray()['data']['filters'])) {
            Log::warning("No filters found for category ID {$categoryId}");
            return null; // No filters found for the category
        }

        $filtersData = $response->toArray()['data']['filters'];
        return FilterEntity::create($filtersData, $this);

    }

    public function findProductIdBySlug(string $slug): ?int
    {
        $this->httpService->setUrl("/catalog?by_slug={$slug}");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new PrestashopConnectorException($this->httpService);
        }

        $products = $response->toArray()['data'] ?? [];
        if (empty($products)) {
            return null; // No product found with the given slug
        }

        return (int) $products['id_product'];
    }

    public function searchProducts(string $query): Collection
    {
        $this->httpService->setUrl("/search?query={$query}&language=1&display=full");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new PrestashopConnectorException($this->httpService);
        }

        $collection = new Collection();
        $products = $response->toArray()['products'] ?? [];
        foreach ($products as $productData) {
            $productData['filters'] = ['id','name','id_default_image','price', 'url'];
            $collection->push(ProductEntity::create($productData, $this));
        }

        return $collection;
    }

    public function getFeaturedPromotions(): Collection
    {
        $products = $this->productsList(filter: new Filter(['on_sale' => true]));

        if(is_null($products) || $products->isEmpty()) {
            return new Collection(); // No promotions found
        }

        return $products;
    }

    /**
     * Updates a product's content in PrestaShop via the custom module endpoint.
     * The product is kept inactive (active=0) unless explicitly set otherwise.
     *
     * @param int   $productId The PrestaShop product ID
     * @param array $data      Fields to update (name, description, description_short, meta_title, meta_description, active)
     * @return bool True on success
     * @throws PrestashopConnectorException on failure
     */
    public function updateProduct(int $productId, array $data): bool
    {
        $psBaseUrl = env('PS_BASE_URL', '');
        $wsKey     = env('WEBSERVICE_KEY', '');

        $client = new \GuzzleHttp\Client(['verify' => false, 'timeout' => 30]);

        try {
            $response = $client->post("https://{$psBaseUrl}/api/products/update", [
                'json'    => array_merge(['id' => $productId], $data),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WS-Key'     => $wsKey,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $ok   = (bool) ($body['success'] ?? false);

            if (!$ok) {
                Log::error("updateProduct: module returned success=false for product #{$productId}. Body: " . json_encode($body));
                throw new \RuntimeException("PrestaShop module returned failure for product #{$productId}");
            }

            Log::info("updateProduct: product #{$productId} updated successfully");
            return true;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error("updateProduct: HTTP error for product #{$productId}: " . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService);
        }
    }

    /**
     * Downloads an image from a URL and uploads it to a PrestaShop product via the
     * native webservice image endpoint (POST /api/images/products/{id}).
     *
     * @param int    $productId The PrestaShop product ID
     * @param string $imageUrl  A publicly accessible URL of the image to upload
     * @return bool True on success
     * @throws PrestashopConnectorException on failure
     */
    public function uploadProductImage(int $productId, string $imageUrl): bool
    {
        $psBaseUrl = env('PS_BASE_URL', '');
        $apiKey    = env('PS_API_KEY', '');

        $client = new \GuzzleHttp\Client(['verify' => false, 'timeout' => 60]);

        try {
            // Download the remote image into a temporary file
            $tmpFile = tempnam(sys_get_temp_dir(), 'ps_img_');
            $imageResponse = $client->get($imageUrl, ['sink' => $tmpFile]);

            $contentType = $imageResponse->getHeaderLine('Content-Type') ?: 'image/jpeg';
            $extension   = str_contains($contentType, 'png') ? 'png' : 'jpg';
            $namedTmp    = $tmpFile . '.' . $extension;
            rename($tmpFile, $namedTmp);

            // Upload to PrestaShop via its native image API
            $uploadResponse = $client->post(
                "https://{$apiKey}@{$psBaseUrl}/psapi/images/products/{$productId}?ws_key={$apiKey}&output_format=JSON",
                [
                    'multipart' => [
                        [
                            'name'     => 'image',
                            'contents' => fopen($namedTmp, 'r'),
                            'filename' => "product_{$productId}.{$extension}",
                        ],
                    ],
                ]
            );

            @unlink($namedTmp);

            $statusCode = $uploadResponse->getStatusCode();
            if ($statusCode >= 400) {
                Log::error("uploadProductImage: PS returned HTTP {$statusCode} for product #{$productId}");
                return false;
            }

            Log::info("uploadProductImage: image uploaded for product #{$productId}");
            return true;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            @unlink($tmpFile ?? '');
            Log::error("uploadProductImage: HTTP error for product #{$productId}: " . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService);
        }
    }}
