<?php
declare(strict_types=1);

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\Client;
use PS\Webservice\Domain\Models\PS\Products\Product;
use PS\Webservice\Service\ElkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'elk:audit-products', description: 'Compare active catalog product IDs with the search index')]
class AuditProductIndex extends Command
{
    public function __construct(private Client $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = [];
        foreach (Product::where('active', 1)->cursor() as $product) {
            $catalog[(int) $product->id_product] = true;
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
                if (($response['_shards']['failed'] ?? 0) > 0 || ($response['timed_out'] ?? false)) {
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

        $missing = array_keys(array_diff_key($catalog, $indexed));
        $extra = array_keys(array_diff_key($indexed, $catalog));
        sort($missing);
        sort($extra);
        $duplicateIds = array_keys($duplicates);
        sort($duplicateIds);
        $output->writeln(json_encode([
            'active_catalog_count' => count($catalog),
            'active_indexed_count' => count($indexed),
            'missing_ids' => $missing,
            'extra_active_ids' => $extra,
            'duplicate_ids' => $duplicateIds,
            'invalid_documents' => $invalid,
        ], JSON_UNESCAPED_SLASHES));

        return $missing !== [] || $extra !== [] || $duplicateIds !== [] || $invalid > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
