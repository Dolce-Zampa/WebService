<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use GuzzleHttp\ClientInterface;
use PS\Webservice\Service\Shipping\PacklinkClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:packlink:register-integration', description: 'Register an authenticated Packlink webhook integration')]
final class RegisterPacklinkIntegrationCommand extends Command
{
    public function __construct(private readonly ClientInterface $http)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirm-registration', null, InputOption::VALUE_NONE, 'Explicitly request registration with Packlink');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('confirm-registration')) {
            $output->writeln('<error>Explicit registration confirmation is required.</error>');
            return Command::FAILURE;
        }
        try {
            $client = new PacklinkClient($this->http, (string) env('PACKLINK_API_URL', 'https://api.packlink.com'), (string) env('PACKLINK_API_KEY', ''));
            $id = $client->registerIntegration((string) env('PACKLINK_INTEGRATION_TYPE', ''), (string) env('PACKLINK_INTEGRATION_GUID', ''),
                (string) env('PACKLINK_INTEGRATION_NAME', ''), (string) env('PACKLINK_WEBHOOK_URL', ''), (string) env('PACKLINK_WEBHOOK_SECRET', ''));
            $output->writeln('Integration ID: ' . $id);
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Registration failed or its outcome is unknown. Check Packlink before retrying.</error>');
            return Command::FAILURE;
        }
    }
}
