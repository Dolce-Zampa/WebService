<?php
declare(strict_types=1);

namespace PS\Webservice\Commands\ElasticSearch;

use Elastic\Elasticsearch\Client;
use PS\Webservice\Service\ProductIndexAudit;
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
        $report = (new ProductIndexAudit($this->client))->scan();
        $missing = array_keys($report['missing_products']);
        unset($report['missing_products']);
        $report['missing_ids'] = $missing;
        $output->writeln(json_encode($report, JSON_UNESCAPED_SLASHES));
        return $missing !== [] || $report['extra_active_ids'] !== [] || $report['duplicate_ids'] !== [] || $report['invalid_documents'] > 0
            ? Command::FAILURE : Command::SUCCESS;
    }
}
