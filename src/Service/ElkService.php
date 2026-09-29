<?php
namespace PS\Webservice\Service;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Service\PS\Product;
use Illuminate\Support\Collection;

class ElkService
{
    protected Client $client;
    protected Product $productService;
    public const QUEUE_NAME = 'elk_queue';
    public const INDEX_PRODUCTS = 'dolcezampa_products';

    public function __construct(Client $clientBuilder, Product $productService)
    {
        $this->client = $clientBuilder;
        $this->productService = $productService;
    }

    public function inxedProduct(int $productId): void
    {
        // Implementation for indexing a single product in ElasticSearch
        $product = ProductEntity::createFromId($productId, $this->productService);
        $document = $this->buildProductDocument($product);
        $this->client->index([
            'index' => self::INDEX_PRODUCTS,
            'id' => $productId,
            'body' => $document
        ]);
    }

    public function bulkIndexProducts(array $productIds): void
    {
        $documents = [];
        foreach ($productIds as $productId) {
            try {
                $product = ProductEntity::createFromId($productId, $this->productService);
                $documents[] = $this->buildProductDocument($product);
            } catch (\Throwable $e) {
                Log::error("ElkService: failed to build document for product ID {$productId}. Error: " . $e->getMessage());
            }
        }
        $this->client->bulk([
            'index' => self::INDEX_PRODUCTS,
            'body' => array_map(fn($doc) => ['index' => ['_id' => $doc['id'] ?? null]] + ['data' => $doc], $documents)
        ]);

    }

    public function bulkIndexCategory(int $categoryId): void
    {
        $listOfProducts = $this->productService->getProductByCategory((string) $categoryId);
        $productIds = array_map(fn($p) => (int) $p['id'], $listOfProducts->toArray());
        if (count($productIds) >= 1) {
            $this->bulkIndexProducts($productIds);
        }
    }

    protected function buildProductDocument(ProductEntity $product): array
    {
        // Implementation for building the product document to be indexed in ElasticSearch
        $p = $product->withFeatures()->toArray();
        // --- Categorie ---
        $categories = [];
        foreach ($p['associations']['categories'] ?? [] as $cat) {
            $categories[] = ['id' => (int) $cat['id'], 'name' => $cat['name']];
        }

        // --- Features (es. "Pelle", "made in italy") ---
        $features = [];
        foreach ($p['associations']['product_features'] ?? [] as $f) {
            $features[] = [
                'id_feature' => (int) $f['id_feature'],
                'value' => $f['value']
            ];
        }

        // --- Attributi varianti (size + color), deduplicati ---
        $attrs = [];
        $seen = [];
        foreach ($p['associations']['combinations'] ?? [] as $combo) {
            foreach ($combo['associations'] ?? [] as $key => $attr) {
                if (!is_array($attr) || !isset($attr['id']))
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
            'quantity' => (int) $p['quantity'],
            'weight' => (float) $p['weight'],
            'id_category_default' => (int) $p['id_category_default'],
            'id_manufacturer' => (int) $p['id_manufacturer'],
            'id_supplier' => (int) $p['id_supplier'],
            'active' => (bool) $p['active'],
            'on_sale' => (bool) ($p['on_sale'] ?? false),
            'visibility' => $p['visibility'] ?? 'both',
            'condition' => $p['condition'] ?? 'new',
            'product_type' => $p['product_type'] ?? 'simple',
            'date_add' => $p['date_add'],
            'date_upd' => $p['date_upd'],
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
        $params = [
            'index' => ElkService::INDEX_PRODUCTS,
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => [
                            'name^3',              // il nome pesa 3x
                            'description_short^2', // descrizione breve 2x
                            'description',         // descrizione completa
                            'meta_title',
                            'meta_description'
                        ],
                        'type' => 'best_fields',
                        'fuzziness' => 'AUTO'      // tollera errori di battitura
                    ]
                ]
            ]
        ];

        $response = $this->client->search($params);

        return collect($response['hits']['hits'] ?? [])
            ->map(fn(array $hit) => ProductEntity::create(
                $hit['_source'],
                $this->productService
            ));
    }
}

