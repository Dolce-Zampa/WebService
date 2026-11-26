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

#[AsCommand(name: 'app:stripe-connect:manual-initial', description: 'Trasferisce a un venditore la quota di un pagamento iniziale')]
final class ManualStripeConnectInitialCommand extends Command
{
    public function __construct(private readonly StripeConnectService $connect)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('order-id', null, InputOption::VALUE_REQUIRED, 'ID ordine PrestaShop')
            ->addOption('seller-id', null, InputOption::VALUE_REQUIRED, 'ID produttore PrestaShop')
            ->addOption('payment-intent', null, InputOption::VALUE_REQUIRED, 'PaymentIntent del pagamento iniziale')
            ->addOption('payment-cents', null, InputOption::VALUE_REQUIRED, 'Importo totale pagato, in centesimi')
            ->addOption('seller-cents', null, InputOption::VALUE_REQUIRED, 'Quota netta venditore facoltativa, in centesimi')
            ->addOption('seller-cent', null, InputOption::VALUE_REQUIRED, 'Alias di --seller-cents')
            ->addOption('confirm-seller-cents', null, InputOption::VALUE_REQUIRED, 'Conferma la quota calcolata nell’anteprima, in centesimi')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Esegue il trasferimento; senza questa opzione mostra solo l’anteprima');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $values = [];
        foreach (['order-id', 'seller-id', 'payment-cents'] as $name) {
            $values[$name] = filter_var($input->getOption($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($values[$name] === false) {
                $io->error("Specificare --{$name} come intero positivo.");
                return Command::INVALID;
            }
        }
        foreach (['seller-cents', 'seller-cent', 'confirm-seller-cents'] as $name) {
            $option = $input->getOption($name);
            $values[$name] = $option === null ? null : filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($values[$name] === false) {
                $io->error("Specificare --{$name} come intero positivo.");
                return Command::INVALID;
            }
        }
        if ($values['seller-cent'] !== null && $values['seller-cents'] !== null) {
            $io->error('Specificare una sola opzione tra --seller-cent e --seller-cents.');
            return Command::INVALID;
        }
        $values['seller-cents'] ??= $values['seller-cent'];
        try {
            $result = $this->connect->manualInitialTransfer(
                $values['order-id'], $values['seller-id'], trim((string) $input->getOption('payment-intent')),
                $values['payment-cents'], $values['seller-cents'], (bool) $input->getOption('execute'), $values['confirm-seller-cents'],
            );
        } catch (\Throwable $exception) {
            $io->error('Operazione non completata: ' . $exception->getMessage());
            $io->warning('Se è stato richiesto --execute, verificare lo stato in Stripe e nel database prima di riprovare.');
            return Command::FAILURE;
        }
        $io->definitionList(...array_map(static fn ($key, $value) => [$key => (string) $value], array_keys($result), array_values($result)));
        if (!$input->getOption('execute')) {
            $io->note('Anteprima: nessun trasferimento eseguito. Dopo la verifica aggiungere --execute; per la quota automatica aggiungere anche --confirm-seller-cents con il valore mostrato.');
        } else {
            $io->success('Trasferimento registrato.');
        }
        return Command::SUCCESS;
    }
}
