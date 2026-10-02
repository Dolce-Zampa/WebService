<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PS\Webservice\Domain\Entities\EntityExceptions;
use PS\Webservice\Domain\Entities\FilterEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\Filter;

class Product extends PrestashopService implements PrestashopServiceInterface
{

    use \PS\Webservice\Traits\UseCache;

    public const CACHE_QUEUE = 'product-cache-jobs';
    public const WARM_QUEUE = 'product-cache-warm-jobs';
    public const CACHE_REVISION = 'product-cache:revision';

    /** The global revision is changed only by a full clear; each tag has its own revision. */
    public static function revisionFor(array $tags): string
    {
        $versions = [Cache::get(self::CACHE_REVISION, '0')];
        foreach ($tags as $tag) {
            $versions[] = Cache::get(self::CACHE_REVISION . ':' . $tag, '0');
        }
        return hash('sha256', json_encode($versions));
    }

    public static function invalidateCacheTags(array $tags): void
    {
        foreach (array_unique($tags) as $tag) {
            Cache::forever(self::CACHE_REVISION . ':' . $tag, bin2hex(random_bytes(16)));
        }
    }

    private function contextKey(): string
    {
        return hash('sha256', $this->httpService->getConfig()->toJson() . $this->httpService->getConfig()->getQueryParams());
    }

    private function productCacheKey(string $key, array $tags): string
    {
        return 'product-cache:v3:' . $this->contextKey() . ':' . self::revisionFor($tags) . ':' . $key;
    }

    /** TTL is in minutes; zero means forever. */
    private function cachedProductData(string $key, array $tags, callable $loader, int $ttl = 0, ?callable $valid = null): mixed
    {
        if (env('APP_DISABLE_CACHE', false)) {
            return $loader();
        }
        $key = $this->productCacheKey($key, $tags);
        $store = Cache::tags($tags);
        $cached = $store->get($key);
        if ($cached !== null && ($valid === null || $valid($cached))) {
            return $cached;
        }
        // Also guard loads of products that embed a bundle modified during this request.
        $revision = self::revisionFor(array_merge($tags, ['product-catalog']));
        $namespace = $store->getTags()->getNamespace();
        $lock = Cache::lock('product-cache-lock:' . sha1($namespace . $key), 120);
        $deadline = microtime(true) + 30;
        while (!$lock->acquire()) {
            if (microtime(true) >= $deadline) {
                throw new \Illuminate\Contracts\Cache\LockTimeoutException();
            }
            usleep(100000);
        }
        try {
            $cached = $store->get($key);
            if ($cached !== null && ($valid === null || $valid($cached))) {
                return $cached;
            }
            $data = $loader();
            if ($data !== null && $store->getTags()->getNamespace() === $namespace && self::revisionFor(array_merge($tags, ['product-catalog'])) === $revision) {
                if ($ttl === 0) {
                    $store->forever($key, $data);
                } else {
                    $store->put($key, $data, $ttl * 60);
                }
            }
            return $data;
        } finally {
            $lock->release();
        }
    }

    private function productApiData(string $url, array $tags = ['product-catalog'], int $ttl = 0): array
    {
        return $this->cachedProductData('api:' . $url, $tags, function () use ($url) {
            $this->httpService->setUrl($url);
            $response = $this->httpService->invoke('GET');
            if ($response->failed()) {
                throw new PrestashopConnectorException($this->httpService);
            }
            return $response->toArray();
        }, $ttl);
    }

    private function snapshotIsCurrent(array $snapshot): bool
    {
        foreach ($snapshot['dependencies'] as $tag => $revision) {
            if (self::revisionFor([$tag]) !== $revision) {
                return false;
            }
        }
        return true;
    }

    public function hasCompleteProductCached(int $id): bool
    {
        if (env('APP_DISABLE_CACHE', false)) {
            return false;
        }
        $tags = ['product:' . $id];
        $snapshot = Cache::tags($tags)->get($this->productCacheKey('detail:' . $id, $tags));
        return is_array($snapshot) && $this->snapshotIsCurrent($snapshot);
    }

    public function reserveWarmup(int $id): bool
    {
        return Cache::add('product-warm-pending:' . $this->contextKey() . ':' . $id, true, 86400);
    }

    public function releaseWarmup(int $id): void
    {
        Cache::forget('product-warm-pending:' . $this->contextKey() . ':' . $id);
    }

    public function getCompleteProductById(int $id): ?ProductEntity
    {
        $snapshot = $this->cachedProductData('detail:' . $id, ['product:' . $id], function () use ($id) {
            $product = $this->getProductById($id);
            if ($product === null) {
                return null;
            }
            $data = $product->withFeatures()->toArray();
            $dependencies = [];
            foreach (array_merge($data['bundles'] ?? [], $data['associations']['accessories'] ?? []) as $related) {
                if (isset($related['id'])) {
                    $tag = 'product:' . (int) $related['id'];
                    $dependencies[$tag] = self::revisionFor([$tag]);
                }
            }
            return ['product' => $data, 'dependencies' => $dependencies];
        }, 0, fn (array $cached) => $this->snapshotIsCurrent($cached));
        return $snapshot === null ? null : ProductEntity::fromSnapshot($snapshot['product'], $this, true);
    }

    public function countProducts(array $filter = []): int
    {
        $queryString = http_build_query(['display' => '[id]'] + $filter);
        $products = $this->productApiData("/products?{$queryString}")['products'] ?? [];
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
        $cacheKey = 'list:' . json_encode([$displayOptions, $filter?->data]);
        $snapshots = $this->cachedProductData($cacheKey, ['product-catalog'], function () use ($displayOptions, $filter) {
            $sorting = $displayOptions['sort'] ?? 'date_add_DESC';
            $displayOptions['sort'] = "[$sorting]";
            $queryString = http_build_query($displayOptions);
            $products = $this->productApiData("/products?{$queryString}&price[original_price][use_tax]=1&price[original_price][use_reduction]=1&date=1")['products'] ?? [];
            $snapshots = [];
            foreach ($products as $productData) {
                $productFilter = $filter ?? new Filter([]);
                if (!$productFilter->match($productData)) {
                    continue;
                }
                $snapshots[] = ProductEntity::create($productFilter->productData, $this)->toArray();
            }
            return $snapshots;
        });
        return new Collection(array_map(fn (array $data) => ProductEntity::fromSnapshot($data, $this), $snapshots));
    }

    /**
     * Retrieves featured products.
     *
     * @return Collection The collection of featured product entities.
     */
    public function getFeaturedProducts(): Collection
    {
        $this->httpService->setUrl("/promotions?debug=true");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new PrestashopConnectorException($this->httpService);
        }

        $collection = new Collection();
        $products = $response->toArray()['data']['products'] ?? [];
        foreach ($products as $productData) {

            try {
                $product = ProductEntity::createFromId($productData['id_product'], $this);
                $collection->push($product);
            } catch (EntityExceptions $e) {
                Log::error("Failed to create ProductEntity for product ID {$productData['id']}: " . $e->getMessage());
                continue; // Skip this product but continue processing others
            }
            
        }

        return $collection;
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
    public function getProductByManufacture(string $manufactureId, ?string $categoryId = null, array $pagination = [], string $sort = 'id_DESC', ?Filter $filters = null): Collection
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

        return $this->getCompleteProductById($productId);
        
    }

    public function getProductById(int $id): ?ProductEntity
    {
        $productRevision = self::revisionFor(['product:' . $id]);
        $data = $this->cachedProductData('base:' . $id, ['product:' . $id], function () use ($id) {
            $this->httpService->setUrl("/products/{$id}?price[original_price][use_tax]=1&price[original_price][use_reduction]=1&display=full");
            $response = $this->httpService->invoke('GET');
            if ($response->failed()) {
                if ($response->getHttpCode() === 404) {
                    return null;
                }
                throw new PrestashopConnectorException($this->httpService);
            }
            $product = new ProductEntity($response->toArray()['products'][0], $this);
            if (!\PS\Webservice\Domain\Entities\Validations\ProductValidator::isValid($product)) {
                throw new \RuntimeException('Incomplete upstream product payload: ' . $id);
            }
            return $product->toArray();
        });
        if (self::revisionFor(['product:' . $id]) === $productRevision && $data !== null && !empty($data['link_rewrite']) && is_string($data['link_rewrite']) && !env('APP_DISABLE_CACHE', false)) {
            Cache::forever('product-slug:v3:' . $this->contextKey() . ':' . sha1($data['link_rewrite']), [
                'id' => $id, 'revision' => $productRevision,
            ]);
        }
        return $data === null ? null : ProductEntity::fromSnapshot($data, $this);
    }

    public function buildFiltersProducts(int $categoryId): ?FilterEntity
    {
        $payload = $this->productApiData("/filters?id_category={$categoryId}");
        if (empty($payload['data']['filters'])) {
            Log::warning("No filters found for category ID {$categoryId}");
            return null; // No filters found for the category
        }

        $filtersData = $payload['data']['filters'];
        return FilterEntity::create($filtersData, $this);

    }

    public function findProductIdBySlug(string $slug): ?int
    {
        $key = 'product-slug:v3:' . $this->contextKey() . ':' . sha1($slug);
        if (!env('APP_DISABLE_CACHE', false)) {
            $mapping = Cache::get($key);
            if (is_array($mapping) && self::revisionFor(['product:' . $mapping['id']]) === $mapping['revision']) {
                return (int) $mapping['id'];
            }
        }
        $catalogRevision = self::revisionFor(['product-catalog']);
        $products = $this->productApiData('/catalog?by_slug=' . rawurlencode($slug))['data'] ?? [];
        if (empty($products)) {
            return null; // No product found with the given slug
        }

        $id = (int) $products['id_product'];
        $productRevision = self::revisionFor(['product:' . $id]);
        if (!env('APP_DISABLE_CACHE', false) && self::revisionFor(['product-catalog']) === $catalogRevision) {
            Cache::forever($key, ['id' => $id, 'revision' => $productRevision]);
        }
        return $id;
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
            $product = ProductEntity::create($productData, $this);
            $collection->push($product);
        }

        return $collection;
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
        try {
            $payload = array_merge(['id' => $productId], $data);
            $this->httpService->setUrl("/catalog/update?debug=true");
            $response = $this->httpService->invoke('POST', $payload);

            if ($response->failed()) {
                throw new PrestashopConnectorException($this->httpService);
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
        $client = new \GuzzleHttp\Client(['verify' => false, 'timeout' => 60]); // FIXME: enable verify in production

        $tmpFile = null;
        $namedTmp = null;
        try {
            // Download the remote image into a temporary file
            $tmpFile = tempnam(sys_get_temp_dir(), 'ps_img_');
            $imageResponse = $client->get($imageUrl, ['sink' => $tmpFile]);

            $contentType = $imageResponse->getHeaderLine('Content-Type') ?: 'image/jpeg';
            $extension   = str_contains($contentType, 'png') ? 'png' : 'jpg';
            $namedTmp    = $tmpFile . '.' . $extension;
            rename($tmpFile, $namedTmp);
            $tmpFile = null; // file has been renamed; clean up via $namedTmp

            // Upload to PrestaShop via its native image API using header-based auth only
            $this->httpService->setUrl("/images/products/{$productId}?output_format=JSON");
            $response = $this->httpService->setMultipartData([
                            'name'     => 'image',
                            'contents' => fopen($namedTmp, 'r'),
                            'filename' => "product_{$productId}.{$extension}",
                        ])->invoke('POST', []);

            @unlink($namedTmp);

            if ($response->failed()) {
                throw new PrestashopConnectorException($this->httpService);
            }

            Log::info("uploadProductImage: image uploaded for product #{$productId}");
            return true;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
            if ($namedTmp !== null) {
                @unlink($namedTmp);
            }
            Log::error("uploadProductImage: HTTP error for product #{$productId}: " . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService);
        }
    }

    /**
     * 
     * @param int $productId
     * @param int $imageId
     * @throws PrestashopConnectorException
     * @return bool
     */
    public function deleteImage(int $productId, int $imageId): bool
    {
        try {
            $this->httpService->setUrl("/images/products/{$productId}/{$imageId}");
            $response = $this->httpService->invoke('DELETE');

            if ($response->failed()) {
                throw new PrestashopConnectorException($this->httpService);
            }

            Log::info("deleteImage: image #{$imageId} deleted for product #{$productId}");
            return true;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error("deleteImage: HTTP error for product #{$productId}, image #{$imageId}: " . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService);
        }
    }
    
}
