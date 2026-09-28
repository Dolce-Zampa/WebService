<?php

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\ClientBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'elk:index-bulk',
    description: 'Index multiple products on elastic search'
)]
class IndexBulkProduct extends Command
{
    protected static $defaultName = 'elk:index-bulk';
    protected static $defaultDescription = 'Index multiple products on elastic search';

    private ClientBuilder $client;

    public function __construct(ClientBuilder $clientBuilder)
    {
        $this->client = $clientBuilder;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription)
        ->addArgument('product_ids', InputArgument::OPTIONAL, 'IDs of the products to index')
        ->addArgument('category_id', InputArgument::OPTIONAL, 'ID of the category to index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
