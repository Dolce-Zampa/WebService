<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Service\Payments\StripePayoutOrderReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:stripe-connect:reconcile-payout-orders', description: 'Riconcilia un payout automatico con gli ordini comprovati')]
final class ReconcileStripePayoutOrdersCommand extends Command
{
    public function __construct(private readonly StripePayoutOrderReconciliationService $service) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account Connect')
            ->addOption('payout', null, InputOption::VALUE_REQUIRED, 'Identificativo payout')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Registra le attribuzioni (default anteprima)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->service->reconcile((string) $input->getOption('account'), (string) $input->getOption('payout'), !(bool) $input->getOption('apply'));
        } catch (\Throwable $e) {
            $io->error('Riconciliazione non riuscita; verificare accesso Stripe e dati dell’account prima di riprovare.');
            return Command::FAILURE;
        }
        $io->writeln(sprintf('Esito: %s; attribuzioni: %d.', $result['status'], $result['allocations']));
        return in_array($result['status'], ['preview', 'recorded', 'already_recorded'], true) ? Command::SUCCESS : Command::FAILURE;
    }
}
