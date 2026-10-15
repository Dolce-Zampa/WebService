<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use DateTimeImmutable;
use PS\Webservice\Service\Financial\FinancialOrderBackfillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:financial:backfill-orders', description: 'Importa nel ledger gli ordini PrestaShop validi già esistenti')]
final class BackfillFinancialOrdersCommand extends Command
{
    public function __construct(private readonly FinancialOrderBackfillService $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Data iniziale ISO-8601')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Data finale ISO-8601 esclusiva')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Numero massimo di ordini (0 = tutti)', 0)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mostra gli ordini idonei senza scrivere nel ledger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $from = $input->getOption('from') ? new DateTimeImmutable((string) $input->getOption('from')) : null;
            $to = $input->getOption('to') ? new DateTimeImmutable((string) $input->getOption('to')) : null;
            $limit = max(0, (int) $input->getOption('limit'));
            $result = $this->backfill->import($from, $to, $limit, (bool) $input->getOption('dry-run'));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('Ordini idonei: %d · creati: %d · già presenti: %d', $result['eligible'], $result['created'], $result['skipped']));
        return Command::SUCCESS;
    }
}
