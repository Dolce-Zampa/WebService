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
    name: 'app:stripe-connect:recover-allocation',
    description: 'Registra e processa un’allocazione Connect per un ordine già pagato'
)]
final class RecoverStripeConnectTransferAllocationCommand extends Command
{
    public function __construct(private readonly StripeConnectService $connect)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('order-id', null, InputOption::VALUE_REQUIRED, 'ID ordine PrestaShop')
            ->addOption('cart-id', null, InputOption::VALUE_REQUIRED, 'ID carrello PrestaShop')
            ->addOption('payment-intent', null, InputOption::VALUE_REQUIRED, 'ID Stripe PaymentIntent')
            ->addOption('transfer-group', null, InputOption::VALUE_OPTIONAL, 'Gruppo Stripe Transfer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderId = filter_var($input->getOption('order-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cartId = filter_var($input->getOption('cart-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $paymentIntentId = trim((string) $input->getOption('payment-intent'));
        $transferGroup = trim((string) ($input->getOption('transfer-group') ?: 'dz_cart_' . (string) $cartId));
        if ($orderId === false || $cartId === false || !preg_match('/^pi_[A-Za-z0-9]+$/', $paymentIntentId)) {
            $io->error('Specificare --order-id, --cart-id e --payment-intent validi.');
            return Command::INVALID;
        }

        try {
            $completed = $this->connect->recoverTransferAllocation((int) $orderId, (int) $cartId, $paymentIntentId, $transferGroup);
        } catch (\Throwable) {
            $io->error('Recupero dell’allocazione Connect non riuscito.');
            return Command::FAILURE;
        }

        if (!$completed) {
            $io->warning('Allocazione salvata in coda; sarà riprovata automaticamente.');
            return Command::SUCCESS;
        }
        $io->success('Allocazione Connect completata.');
        return Command::SUCCESS;
    }
}
