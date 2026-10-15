<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Financial\FinancialWeekPeriod;
use PS\Webservice\Repositories\WeeklySellerSummaryDeliveryRepository;
use PS\Webservice\Repositories\WeeklySellerSummaryRecipientRepository;
use PS\Webservice\Service\Financial\WeeklySellerFinancialSummaryService;
use PS\Webservice\Service\MailerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'financial:send-weekly-seller-summary',
    description: 'Invia il riepilogo finanziario della settimana precedente ai venditori'
)]
final class SendWeeklySellerFinancialSummaryCommand extends Command
{
    public function __construct(
        private readonly WeeklySellerFinancialSummaryService $summaries,
        private readonly WeeklySellerSummaryRecipientRepository $recipients,
        private readonly WeeklySellerSummaryDeliveryRepository $deliveries,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Timezone IANA del periodo (default FINANCIAL_SUMMARY_TIMEZONE o UTC)')
            ->addOption('artisan', null, InputOption::VALUE_REQUIRED, 'Invia a un singolo venditore')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcola senza riservare o inviare consegne');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $timezone = new DateTimeZone((string) ($input->getOption('timezone') ?: env('FINANCIAL_SUMMARY_TIMEZONE', 'UTC')));
        } catch (\Throwable) {
            $output->writeln('<error>Timezone del riepilogo finanziario non valida.</error>');
            return Command::INVALID;
        }

        $artisanOption = $input->getOption('artisan');
        if ($artisanOption !== null && (!ctype_digit((string) $artisanOption) || (int) $artisanOption < 1)) {
            $output->writeln('<error>Il venditore deve essere un identificativo positivo.</error>');
            return Command::INVALID;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $period = FinancialWeekPeriod::precedingWeek($now, $timezone);
        $dryRun = (bool) $input->getOption('dry-run');
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->recipients->recipients($artisanOption === null ? null : (int) $artisanOption) as $recipient) {
            $artisanId = (int) $recipient->artisan_id;
            $summary = $this->summaries->summarize($artisanId, $period);
            if ($dryRun) {
                $output->writeln(sprintf('Venditore %d: riepilogo %s calcolato.', $artisanId, $period->key()));
                continue;
            }

            $claim = $this->deliveries->claim($artisanId, $period, $now);
            if ($claim === null) {
                $skipped++;
                continue;
            }

            try {
                $this->mailer->sendWeeklySellerFinancialSummary(
                    (string) $recipient->email,
                    (string) $recipient->name,
                    $summary,
                );
                $this->deliveries->markSent($claim, new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $sent++;
            } catch (\Throwable $exception) {
                $this->deliveries->markFailed($claim, $exception, new DateTimeImmutable('now', new DateTimeZone('UTC')));
                // Do not log recipient address or report data.
                Log::warning('Weekly seller financial summary delivery failed.', [
                    'artisan_id' => $artisanId,
                    'period' => $period->key(),
                    'attempt' => $claim->attempt,
                    'failure_code' => (new \ReflectionClass($exception))->getShortName(),
                ]);
                $failed++;
            }
        }

        $output->writeln(sprintf('Riepiloghi inviati: %d; già consegnati/in lavorazione: %d; errori: %d.', $sent, $skipped, $failed));
        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
