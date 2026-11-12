<?php
declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Commands\Scheduler\GiftCardMaintenanceMessageHandler;
use PS\Webservice\Service\GiftCard\GiftCardApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:gift-card:maintenance', description: 'Elabora le email gift card e le prenotazioni scadute')]
final class GiftCardMaintenanceCommand extends Command
{
    public function __construct(private readonly GiftCardMaintenanceMessageHandler $maintenance)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->maintenance->runMaintenance();
        } catch (GiftCardApiException $error) {
            $io->error('Manutenzione gift card non riuscita: ' . $error->errorCode . ' (HTTP ' . $error->httpStatus . ').');
            return Command::FAILURE;
        } catch (\InvalidArgumentException $error) {
            $io->error('Configurazione gift card non valida: controlla GIFT_CARD_API_URL e la chiave di manutenzione.');
            return Command::FAILURE;
        } catch (\Throwable $error) {
            $io->error('Manutenzione gift card non riuscita. Controlla la configurazione e riprova.');
            return Command::FAILURE;
        }

        if (!isset($result['emails_sent'], $result['expired_reservations'])
            || !is_int($result['emails_sent']) || $result['emails_sent'] < 0
            || !is_int($result['expired_reservations']) || $result['expired_reservations'] < 0) {
            $io->error('Risposta della manutenzione gift card non valida.');
            return Command::FAILURE;
        }
        $io->success(sprintf('Email accettate per l’invio: %d. Prenotazioni scadute rilasciate: %d.',
            $result['emails_sent'], $result['expired_reservations']));
        return Command::SUCCESS;
    }
}
