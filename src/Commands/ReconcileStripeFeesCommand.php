<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Http\Controller\StripeWebhookController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:stripe:reconcile-fees', description: 'Riconcilia le fee Stripe per un intervallo di Charge')]
final class ReconcileStripeFeesCommand extends Command
{
    /** @param ?\Closure(array, ?string): array{data: array, has_more: bool} $listCharges */
    public function __construct(private readonly StripeWebhookController $controller, private readonly ?\Closure $listCharges = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Inizio incluso, data UTC YYYY-MM-DD');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Fine esclusa, data UTC YYYY-MM-DD');
        $this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account Stripe Connect opzionale');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Registra le fee; senza questa opzione esegue solo la simulazione');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = $this->date($input->getOption('from'));
        $to = $this->date($input->getOption('to'));
        $account = $input->getOption('account');
        if ($from === null || $to === null || $from >= $to || ($account !== null && (!is_string($account) || !preg_match('/^acct_[A-Za-z0-9]+$/', $account)))) {
            $io->error('Specificare un intervallo UTC valido con --from e --to e un eventuale account Stripe valido.');
            return Command::INVALID;
        }
        $counts = ['recorded' => 0, 'ready' => 0, 'zero_fee' => 0, 'skipped' => 0, 'pending' => 0, 'failed' => 0];
        $cursor = null;
        try {
            do {
                $params = ['limit' => 100, 'created' => ['gte' => $from, 'lt' => $to], 'expand' => ['data.balance_transaction']];
                if ($cursor !== null) $params['starting_after'] = $cursor;
                if ($this->listCharges !== null) {
                    $page = ($this->listCharges)($params, $account);
                } else {
                    $client = new \Stripe\StripeClient((string) env('STRIPE_API_KEY', ''));
                    $result = $client->charges->all($params, $account === null ? [] : ['stripe_account' => $account]);
                    $page = ['data' => $result->data, 'has_more' => $result->has_more];
                }
                if (!isset($page['data'], $page['has_more']) || !is_array($page['data']) || !is_bool($page['has_more'])) throw new \RuntimeException('Invalid Stripe page.');
                $previous = $cursor;
                foreach ($page['data'] as $charge) {
                    if (!$charge instanceof \Stripe\StripeObject || !is_string($charge->id ?? null) || !preg_match('/^ch_[A-Za-z0-9]+$/', $charge->id)) throw new \RuntimeException('Invalid Stripe charge.');
                    $cursor = $charge->id;
                    try {
                        $status = $this->controller->reconcileStripeCharge($charge, $account, !$input->getOption('apply'));
                        if (!array_key_exists($status, $counts)) throw new \RuntimeException('Invalid reconciliation result.');
                        ++$counts[$status];
                        if ($status === 'pending') $io->writeln('Charge ' . $cursor . ': balance transaction non ancora disponibile.');
                    } catch (\Throwable) {
                        ++$counts['failed'];
                        $io->writeln('Charge ' . $cursor . ': riconciliazione non riuscita.');
                    }
                }
                if ($page['has_more'] && ($cursor === null || $cursor === $previous)) throw new \RuntimeException('Stripe pagination did not advance.');
            } while ($page['has_more']);
        } catch (\Throwable) {
            $io->error('Lettura delle Charge Stripe non riuscita; intervallo non completamente esaminato.');
            return Command::FAILURE;
        }
        $io->writeln(sprintf('%s: elaborate=%d, pronte=%d, fee zero=%d, ignorate=%d, in attesa=%d, errori=%d.',
            $input->getOption('apply') ? 'Applicazione' : 'Simulazione', $counts['recorded'], $counts['ready'], $counts['zero_fee'], $counts['skipped'], $counts['pending'], $counts['failed']));
        return $counts['failed'] > 0 || $counts['pending'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function date(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value ? $date->getTimestamp() : null;
    }
}
