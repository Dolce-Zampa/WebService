<?php

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\ClientBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'elk:index',
    description: 'Index a product on elastic search'
)]
class IndexProduct extends Command
{
    protected static $defaultName = 'elk:index';
    protected static $defaultDescription = 'Index a product on elastic search';

    private ClientBuilder $client;

    public function __construct(ClientBuilder $clientBuilder)
    {
        $this->client = $clientBuilder;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription)
        ->addArgument('product_id', InputArgument::REQUIRED, 'ID of the product to index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        

        return Command::SUCCESS;
    }
}
