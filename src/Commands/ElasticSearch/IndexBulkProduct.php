<?php

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\Client;
use PS\Webservice\Service\ElkService;
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

    private Client $client;
    private ElkService $elkService;

    public function __construct(Client $clientBuilder, ElkService $elkService)
    {
        $this->client = $clientBuilder;
        $this->elkService = $elkService;
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
        $productIds = array_map('intval', explode(',', $input->getArgument('product_ids') ?? ''));
        if(count($productIds) >= 1) {
            $this->elkService->bulkIndexProducts($productIds);
        }

        $categoryId = (int) $input->getArgument('category_id');
        if($categoryId > 0) {
            $this->elkService->bulkIndexCategory($categoryId);
        }
        return Command::SUCCESS;
    }
}
