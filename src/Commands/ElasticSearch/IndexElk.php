<?php

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\Client;
use PS\Webservice\Service\ElkService;
use PS\Webservice\Service\RedisQueue;
use Symfony\Component\Console\Command\Command;

class IndexElk extends Command
{
    protected Client $client;
    protected ElkService $elkService;
    protected RedisQueue $queue;

    public function __construct(Client $clientBuilder, ElkService $elkService, RedisQueue $queue)
    {
        $this->client = $clientBuilder;
        $this->elkService = $elkService;
        $this->queue = $queue;
        parent::__construct();
    }

    protected function queue(array $toQueue): void
    {
        $this->queue->push(ElkService::QUEUE_NAME, $toQueue);
    }

}
