<?php
namespace PS\Webservice\Service;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Capsule\Manager as DB;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Service\PS\Product;
use Illuminate\Support\Collection;

class ElkService
{
    protected Client $client;
    protected Product $productService;
    public const QUEUE_NAME = 'elk_queue';
    public const INDEX_PRODUCTS = 'dolcezampa_products';
    public const FAILED_QUEUE = 'elk_queue_failed';

    public function __construct(Client $clientBuilder, Product $productService)
    {
        $this->client = $clientBuilder;
        $this->productService = $productService;
    }

    public function inxedProduct(int $productId): void
    {
        $this->bulkIndexProducts([$productId]);
    }

    public function bulkIndexProducts(array $productIds): void
    {
        $body = [];
        $failures = [];
        $operations = 0;
        foreach (array_unique($productIds) as $productId) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }
            try {
                $product = $this->productService->getProductById($productId);
                if ($product === null) {
                    $body[] = ['delete' => ['_id' => (string) $productId]];
                    $operations++;
                    continue;
                }
                $document = $this->buildProductDocument($product);
                $body[] = ['index' => ['_id' => (string) $productId]];
                $body[] = $document;
                $operations++;
            } catch (\Throwable $e) {
                Log::error("ElkService: failed to build document for product ID {$productId}. Error: " . $e->getMessage());
                $failures[] = $productId;
            }
        }
        if ($body !== []) {
            $response = $this->client->bulk(['index' => self::INDEX_PRODUCTS, 'body' => $body]);
            if (!isset($response['items']) || count($response['items']) !== $operations) {
                throw new \RuntimeException('Elasticsearch returned an incomplete bulk response');
            }
            foreach ($response['items'] ?? [] as $item) {
                $operation = array_key_first($item);
                $result = $item[$operation];
                $status = (int) ($result['status'] ?? 500);
                if ($status >= 300 && !($operation === 'delete' && $status === 404)) {
                    $failures[] = $result['_id'] ?? 'unknown';
                    Log::error('ElkService: bulk item failed', ['operation' => $operation, 'result' => $result]);
                }
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException('Elasticsearch indexing failed for product IDs: ' . implode(', ', $failures));
        }
    }

    public function bulkIndexCategory(int $categoryId): void
    {
        DB::table('category_product')->where('id_category', $categoryId)
            ->chunkById(100, function ($products): void {
                $this->bulkIndexProducts($products->pluck('id_product')->map(fn ($id) => (int) $id)->all());
            }, 'id_product');
    }

    protected function buildProductDocument(ProductEntity $product): array
    {
        // Implementation for building the product document to be indexed in ElasticSearch
        $p = $product->toArray();
        // --- Categorie ---
        $categories = [];
        foreach ($p['associations']['categories'] ?? [] as $cat) {
            $categories[] = ['id' => (int) $cat['id'], 'name' => $cat['name'] ?? ''];
        }

        // --- Features (es. "Pelle", "made in italy") ---
        $features = [];
        foreach ($p['associations']['product_features'] ?? [] as $f) {
            $features[] = [
                'id_feature' => (int) ($f['id_feature'] ?? $f['id'] ?? 0),
                'value' => $f['value'] ?? ''
            ];
        }

        // --- Attributi varianti (size + color), deduplicati ---
        $attrs = [];
        $seen = [];
        foreach ($p['associations']['combinations'] ?? [] as $combo) {
            foreach ($combo['associations'] ?? [] as $key => $attr) {
                if (!is_array($attr) || !isset($attr['id'], $attr['id_attribute_group'], $attr['type'], $attr['name']))
                    continue;
                $sig = $attr['id_attribute_group'] . ':' . $attr['id'];
                if (isset($seen[$sig]))
                    continue;
                $seen[$sig] = true;
                $attrs[] = [
                    'group' => $attr['type'],       // "size" | "color"
                    'name' => $attr['name'],
                    'color' => $attr['color'] ?? null
                ];
            }
        }

        // --- Rating medio dalle recensioni approvate ---
        $reviews = array_filter($p['reviews'] ?? [], fn($r) => ($r['status'] ?? '') === 'approved');
        $ratingCount = count($reviews);
        $ratingAvg = $ratingCount > 0
            ? array_sum(array_column($reviews, 'rating')) / $ratingCount
            : 0.0;

        return [
            'id_product' => (int) $p['id'],
            'name' => $p['name'],
            'description' => strip_tags($p['description'] ?? ''),
            'description_short' => strip_tags($p['description_short'] ?? ''),
            'meta_title' => $p['meta_title'] ?? '',
            'meta_description' => $p['meta_description'] ?? '',
            'reference' => $p['reference'] ?? '',
            'link_rewrite' => $p['link_rewrite'] ?? '',
            'url' => $p['url'] ?? '',
            'ean13' => $p['ean13'] ?? '',
            'price' => (float) $p['price'],
            'original_price' => (float) ($p['original_price'] ?? 0),
            'wholesale_price' => (float) ($p['wholesale_price'] ?? 0),
            'quantity' => (int) ($p['quantity'] ?? 0),
            'weight' => (float) ($p['weight'] ?? 0),
            'id_category_default' => (int) ($p['id_category_default'] ?? 0),
            'id_manufacturer' => (int) ($p['id_manufacturer'] ?? 0),
            'id_supplier' => (int) ($p['id_supplier'] ?? 0),
            'active' => (bool) $p['active'],
            'on_sale' => (bool) ($p['on_sale'] ?? false),
            'visibility' => $p['visibility'] ?? 'both',
            'condition' => $p['condition'] ?? 'new',
            'product_type' => $p['product_type'] ?? 'simple',
            'date_add' => $p['date_add'] ?? null,
            'date_upd' => $p['date_upd'] ?? null,
            'categories' => $categories,
            'features' => $features,
            'attributes' => $attrs,
            'id_default_image' => (int) ($p['id_default_image'] ?? 0),
            'rating_avg' => round($ratingAvg, 2),
            'rating_count' => $ratingCount,
        ];
    }

    /**
     * Summary of searchProductsByName
     * @param string $query
     * @return array<int:ProductEntity>
     */
    public function searchProductsByName(string $query): Collection
    {
        $fields = [
            'name^3', 'description_short^2', 'description', 'meta_title', 'meta_description',
            'data.name^3', 'data.description_short^2', 'data.description', 'data.meta_title', 'data.meta_description',
        ];
        $matches = [
            ['multi_match' => ['query' => $query, 'fields' => $fields, 'type' => 'phrase_prefix']],
            ['multi_match' => ['query' => $query, 'fields' => $fields, 'type' => 'best_fields', 'operator' => 'and']],
        ];
        $compact = preg_replace('/[\s-]+/u', '', trim($query));
        if ($compact !== null && $compact !== '' && $compact !== $query) {
            $matches[] = ['multi_match' => ['query' => $compact, 'fields' => $fields, 'type' => 'phrase_prefix']];
        }
        foreach (['reference', 'ean13', 'data.reference.keyword', 'data.ean13.keyword'] as $field) {
            $matches[] = ['term' => [$field => $query]];
        }
        $response = $this->client->search([
            'index' => self::INDEX_PRODUCTS,
            'scroll' => '2m',
            'body' => [
                'size' => 200,
                'query' => ['bool' => ['should' => $matches, 'minimum_should_match' => 1]],
            ],
        ]);

        $products = [];
        $canonical = [];
        $scrollId = null;
        try {
            do {
                $scrollId = $response['_scroll_id'] ?? $scrollId;
                if (($response['_shards']['failed'] ?? 0) > 0 || ($response['timed_out'] ?? false)) {
                    throw new \RuntimeException('Elasticsearch returned incomplete search results');
                }
                $hits = $response['hits']['hits'] ?? [];
                foreach ($hits as $hit) {
                    $wrapped = isset($hit['_source']['data']);
                    $source = $hit['_source']['data'] ?? $hit['_source'] ?? [];
                    $id = (int) ($source['id_product'] ?? 0);
                    if ($id <= 0 || !isset($source['name'], $source['price'], $source['url'], $source['active']) || $source['name'] === '' || $source['url'] === '') {
                        throw new \RuntimeException('Incomplete product in Elasticsearch search results');
                    }
                    if (!(bool) $source['active'] || ($wrapped && isset($canonical[$id]))) {
                        continue;
                    }
                    $source['id'] = $id;
                    $imageId = (int) ($source['id_default_image'] ?? 0);
                    $source['associations']['images'] = $imageId > 0 ? [['id' => $imageId]] : [];
                    $products[$id] = ProductEntity::fromSnapshot($source, $this->productService);
                    if (!$wrapped) {
                        $canonical[$id] = true;
                    }
                }
                if ($hits === []) {
                    break;
                }
                if (!$scrollId) {
                    throw new \RuntimeException('Elasticsearch did not return a scroll ID');
                }
                $response = $this->client->scroll(['body' => ['scroll_id' => $scrollId, 'scroll' => '2m']]);
            } while (true);
        } finally {
            if ($scrollId) {
                try {
                    $this->client->clearScroll(['body' => ['scroll_id' => [$scrollId]]]);
                } catch (\Throwable $e) {
                    Log::warning('ElkService: could not clear search scroll: ' . $e->getMessage());
                }
            }
        }

        return collect(array_values($products));
    }
}

