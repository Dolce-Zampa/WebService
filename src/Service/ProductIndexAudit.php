<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use Elastic\Elasticsearch\Client;
use PS\Webservice\Domain\Models\PS\Products\Product;

final class ProductIndexAudit
{
    public function __construct(private Client $client) {}

    /** Scan the complete index before reporting missing IDs; partial results must never enqueue jobs. */
    public function scan(): array
    {
        $catalog = [];
        foreach (Product::where('active', 1)->cursor() as $product) {
            $catalog[(int) $product->id_product] = (string) ($product->date_upd ?? '');
        }

        $indexed = [];
        $seen = [];
        $duplicates = [];
        $invalid = 0;
        $response = $this->client->search([
            'index' => ElkService::INDEX_PRODUCTS,
            'scroll' => '2m',
            'body' => ['size' => 500, '_source' => ['id_product', 'name', 'price', 'url', 'active', 'data.id_product', 'data.name', 'data.price', 'data.url', 'data.active'], 'query' => ['match_all' => new \stdClass()]],
        ]);
        $scrollId = null;
        try {
            do {
                $scrollId = $response['_scroll_id'] ?? $scrollId;
                if (($response['_shards']['failed'] ?? 0) > 0 || ($response['timed_out'] ?? false) || !isset($response['hits']['hits']) || !is_array($response['hits']['hits'])) {
                    throw new \RuntimeException('Elasticsearch returned incomplete audit results');
                }
                $hits = $response['hits']['hits'] ?? [];
                foreach ($hits as $hit) {
                    $source = $hit['_source']['data'] ?? $hit['_source'] ?? [];
                    $id = (int) ($source['id_product'] ?? 0);
                    if ($id <= 0 || !isset($source['name'], $source['price'], $source['url'], $source['active']) || $source['name'] === '' || $source['url'] === '') {
                        $invalid++;
                        continue;
                    }
                    if (isset($seen[$id])) {
                        $duplicates[$id] = true;
                    }
                    $seen[$id] = true;
                    if ((bool) $source['active']) {
                        $indexed[$id] = true;
                    }
                }
                if ($hits === []) {
                    break;
                }
                if (!$scrollId) {
                    throw new \RuntimeException('Elasticsearch did not return an audit scroll ID');
                }
                $response = $this->client->scroll(['body' => ['scroll_id' => $scrollId, 'scroll' => '2m']]);
            } while (true);
        } finally {
            if ($scrollId) {
                $this->client->clearScroll(['body' => ['scroll_id' => [$scrollId]]]);
            }
        }

        $missing = array_diff_key($catalog, $indexed);
        $extra = array_keys(array_diff_key($indexed, $catalog));
        ksort($missing);
        sort($extra);
        $duplicateIds = array_keys($duplicates);
        sort($duplicateIds);
        return [
            'active_catalog_count' => count($catalog),
            'active_indexed_count' => count($indexed),
            'missing_products' => $missing,
            'extra_active_ids' => $extra,
            'duplicate_ids' => $duplicateIds,
            'invalid_documents' => $invalid,
        ];
    }
}
