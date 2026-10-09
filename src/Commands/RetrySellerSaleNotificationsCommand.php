<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Service\Financial\SellerSaleNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Intended for the scheduler. Only failed operational deliveries are retried;
 * the unique order/seller journal row prevents a successful email from being
 * sent again.
 */
#[AsCommand(
    name: 'app:financial:retry-seller-sale-notifications',
    description: 'Riprova le notifiche di vendita al venditore non consegnate'
)]
final class RetrySellerSaleNotificationsCommand extends Command
{
    public function __construct(private readonly SellerSaleNotificationService $notifications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Numero massimo di notifiche da riprovare', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false) {
            (new SymfonyStyle($input, $output))->error('Il limite deve essere un intero positivo.');
            return Command::INVALID;
        }

        $this->notifications->retryFailedNotifications((int) $limit);
        (new SymfonyStyle($input, $output))->success('Retry notifiche vendita completato.');

        return Command::SUCCESS;
    }
}
