<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Service\Payments\StripeManualPayoutAttributionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:stripe-connect:attribute-payout', description: 'Registra o storna una attribuzione payout documentata, senza muovere fondi')]
final class ManualStripePayoutAttributionCommand extends Command
{
    public function __construct(private readonly StripeManualPayoutAttributionService $service) { parent::__construct(); }

    protected function configure(): void
    {
        foreach (['account', 'payout', 'transfer', 'amount-minor', 'reverse-id', 'key', 'reason', 'evidence', 'operator'] as $option) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED);
        }
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Registra; senza questa opzione esegue una simulazione.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string) $input->getOption('key');
        $reason = (string) $input->getOption('reason');
        $evidence = (string) $input->getOption('evidence');
        $operator = (string) $input->getOption('operator');
        $dryRun = !$input->getOption('apply');
        try {
            $reverse = $input->getOption('reverse-id');
            if ($reverse !== null) {
                if (filter_var($reverse, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                    || $input->getOption('account') !== null || $input->getOption('payout') !== null
                    || $input->getOption('transfer') !== null || $input->getOption('amount-minor') !== null) throw new \InvalidArgumentException('Invalid reversal arguments.');
                $result = $this->service->reverse((int) $reverse, $key, $reason, $evidence, $operator, $dryRun);
            } else {
                $amount = $input->getOption('amount-minor');
                if (filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new \InvalidArgumentException('Invalid amount.');
                $result = $this->service->attribute((string) $input->getOption('account'), (string) $input->getOption('payout'),
                    (string) $input->getOption('transfer'), (int) $amount, $key, $reason, $evidence, $operator, $dryRun);
            }
        } catch (\Throwable) {
            $io->error('Attribuzione non registrata: verificare identificativi, evidenze, importi, stati e precedenti attribuzioni.');
            return Command::FAILURE;
        }
        $id = $result === 'preview' ? null : $this->service->idForKey($key);
        $io->success('Esito: ' . $result . ($id === null ? '' : '; ID journal: ' . $id) . '. Nessun fondo è stato movimentato.');
        return Command::SUCCESS;
    }
}
