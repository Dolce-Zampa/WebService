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
    name: 'elk:index',
    description: 'Index a product on elastic search'
)]
class IndexProduct extends IndexElk
{
    protected static $defaultName = 'elk:index';
    protected static $defaultDescription = 'Index a product on elastic search';

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription)
        ->addArgument('product_id', InputArgument::REQUIRED, 'ID of the product to index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $productId = (int) $input->getArgument('product_id');
        $this->queue(['product_ids' => [$productId]]);
        return Command::SUCCESS;
    }
}
