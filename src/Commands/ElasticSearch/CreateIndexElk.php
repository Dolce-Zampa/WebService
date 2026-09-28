<?php

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'elk:create-index',
    description: 'Index a new product in ElasticSearch'
)]
class CreateIndexElk extends Command
{
    protected static $defaultName = 'elk:create-index';
    protected static $defaultDescription = 'Genera un nuovo indice in ElasticSearch';

    private ClientBuilder $client;

    public function __construct(ClientBuilder $clientBuilder)
    {
        $this->client = $clientBuilder;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->client->indices()->create([
            'index' => 'dolcezampa_products',
            'body' => [
                'settings' => [
                    'analysis' => [
                        'analyzer' => [
                            'italian_custom' => [
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => ['lowercase', 'italian_stop', 'italian_stemmer', 'asciifolding']
                            ]
                        ]
                    ]
                ],
                'mappings' => [
                    'properties' => [
                        // --- Ricerca full-text (analyzed) ---
                        'name' => [
                            'type' => 'text',
                            'analyzer' => 'italian_custom',
                            'fields' => [
                                'keyword' => ['type' => 'keyword'] // per sorting/aggregazioni
                            ]
                        ],
                        'description' => ['type' => 'text', 'analyzer' => 'italian_custom'],
                        'description_short' => ['type' => 'text', 'analyzer' => 'italian_custom'],
                        'meta_title' => ['type' => 'text', 'analyzer' => 'italian_custom'],
                        'meta_description' => ['type' => 'text', 'analyzer' => 'italian_custom'],

                        // --- Identificativi esatti (keyword) ---
                        'reference' => ['type' => 'keyword'],
                        'link_rewrite' => ['type' => 'keyword'],
                        'url' => ['type' => 'keyword'],
                        'ean13' => ['type' => 'keyword'],

                        // --- Numerici (per filtri e range) ---
                        'price' => ['type' => 'float'],
                        'original_price' => ['type' => 'float'],
                        'wholesale_price' => ['type' => 'float'],
                        'quantity' => ['type' => 'integer'],
                        'weight' => ['type' => 'float'],
                        'id_category_default' => ['type' => 'integer'],
                        'id_manufacturer' => ['type' => 'integer'],
                        'id_supplier' => ['type' => 'integer'],
                        'active' => ['type' => 'boolean'],
                        'on_sale' => ['type' => 'boolean'],
                        'visibility' => ['type' => 'keyword'],
                        'condition' => ['type' => 'keyword'],
                        'product_type' => ['type' => 'keyword'],

                        // --- Date ---
                        'date_add' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
                        'date_upd' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],

                        // --- Tassonomie (per aggregazioni/facet) ---
                        'categories' => [
                            'type' => 'nested',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'keyword']
                            ]
                        ],
                        'manufacturer_name' => ['type' => 'keyword'],
                        'features' => [
                            'type' => 'nested',
                            'properties' => [
                                'id_feature' => ['type' => 'integer'],
                                'value' => ['type' => 'keyword']
                            ]
                        ],

                        // --- Varianti (colore/taglia) ---
                        'attributes' => [
                            'type' => 'nested',
                            'properties' => [
                                'group' => ['type' => 'keyword'],   // "size", "color"
                                'name' => ['type' => 'keyword'],   // "2XS", "fucsia"
                                'color' => ['type' => 'keyword']    // "#f400a1"
                            ]
                        ],

                        // --- Immagine principale (per mostrare risultati) ---
                        'id_default_image' => ['type' => 'integer'],

                        // --- Valutazione media (comodo per sorting "più votati") ---
                        'rating_avg' => ['type' => 'float'],
                        'rating_count' => ['type' => 'integer']
                    ]
                ]
            ]
        ]);

        return Command::SUCCESS;
    }
}
