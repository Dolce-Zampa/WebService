<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Service\Payments\StripeConnectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stripe-connect:retry-pending-transfers',
    description: 'Riprova i trasferimenti Stripe Connect rimasti in attesa'
)]
final class RetryPendingStripeConnectTransfersCommand extends Command
{
    public function __construct(private readonly StripeConnectService $connect)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Numero massimo di trasferimenti da riprovare', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false) {
            (new SymfonyStyle($input, $output))->error('Il limite deve essere un intero positivo.');
            return Command::INVALID;
        }

        try {
            $settled = $this->connect->retryPendingTransfers((int) $limit);
        } catch (\Throwable $exception) {
            (new SymfonyStyle($input, $output))->error('Retry trasferimenti Connect non riuscito.');
            return Command::FAILURE;
        }

        (new SymfonyStyle($input, $output))->success(sprintf('Retry trasferimenti Connect completato: %d payout regolati.', $settled));
        return Command::SUCCESS;
    }
}
