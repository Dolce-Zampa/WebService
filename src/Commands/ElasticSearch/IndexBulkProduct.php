<?php

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\Client;
use Predis\Command\Argument\TimeSeries\AddArguments;
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
class IndexBulkProduct extends IndexElk
{
    protected static $defaultName = 'elk:index-bulk';
    protected static $defaultDescription = 'Index multiple products on elastic search';

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription)
        ->addArgument('product_ids', InputArgument::OPTIONAL, 'IDs of the products to index')
        ->addArgument('category_id', InputArgument::OPTIONAL, 'ID of the category to index')
        ->addArgument('limit', InputArgument::OPTIONAL, 'Limit to bulk');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $productIds = array_map('intval', explode(',', $input->getArgument('product_ids') ?? ''));
        $limit = (int) $input->getArgument('limit');
        if(count($productIds) >= 1) {
            $split = array_chunk($productIds, $limit);
            foreach ($split as $chunk) {
                $this->queue(['product_ids' => $chunk]);
            }
        }

        $categoryId = (int) $input->getArgument('category_id');
        if($categoryId > 0) {
            $this->queue(['category_id' => $categoryId]);
        }
        return Command::SUCCESS;
    }
}
