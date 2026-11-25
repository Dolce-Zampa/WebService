<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Service\Payments\StripeConnectPayoutService;
use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:stripe-connect:backfill-payouts', description: 'Riconcilia i payout esistenti di un account Stripe Connect')]
final class BackfillStripeConnectPayoutsCommand extends Command
{
    /** @param null|\Closure(string): iterable<\Stripe\Payout> $listPayouts */
    public function __construct(private readonly StripeConnectPayoutService $payouts, private readonly ?\Closure $listPayouts = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Identificativo dell’account Connect')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Salva gli snapshot mancanti (senza questa opzione mostra solo un’anteprima)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $account = (string) $input->getOption('account');
        if (!preg_match('/^acct_[A-Za-z0-9]+$/D', $account)) {
            $io->error('Specifica un account Connect valido con --account.');
            return Command::INVALID;
        }
        $dryRun = !(bool) $input->getOption('apply');
        $observedAt = time();
        $eligible = 0;
        $skipped = 0;
        try {
            $this->payouts->assertKnownAccount($account);
            if ($this->listPayouts !== null) {
                $payouts = ($this->listPayouts)($account);
            } else {
                $key = (string) env('STRIPE_API_KEY', '');
                if ($key === '') {
                    throw new \RuntimeException('STRIPE_API_KEY non configurata.');
                }
                $client = new StripeClient($key);
                $payouts = $client->payouts->all(['limit' => 100], ['stripe_account' => $account])->autoPagingIterator();
            }
            foreach ($payouts as $payout) {
                if ($this->payouts->recordSnapshot($account, $payout, $observedAt, $dryRun)) {
                    $eligible++;
                } else {
                    $skipped++;
                }
            }
        } catch (\Throwable $exception) {
            $io->error('Riconciliazione interrotta. Le eventuali osservazioni già salvate restano disponibili; ripetere il comando è sicuro.');
            return Command::FAILURE;
        }
        $io->success(sprintf('%s: %d payout %s, %d già presenti.', $dryRun ? 'Anteprima' : 'Riconciliazione', $eligible, $dryRun ? 'da acquisire' : 'acquisiti', $skipped));
        return Command::SUCCESS;
    }
}
