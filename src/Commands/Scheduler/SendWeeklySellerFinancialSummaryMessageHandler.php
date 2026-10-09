<?php

declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Commands\SendWeeklySellerFinancialSummaryCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendWeeklySellerFinancialSummaryMessageHandler
{
    public function __construct(private readonly SendWeeklySellerFinancialSummaryCommand $command)
    {
    }

    public function __invoke(SendWeeklySellerFinancialSummaryMessage $message): void
    {
        $output = new BufferedOutput();
        $exitCode = $this->command->run(new ArrayInput([]), $output);

        Log::info('Scheduler: weekly seller financial summaries completed.', [
            'exit_code' => $exitCode,
            // The command output contains counts only, never recipients/data.
            'output' => $output->fetch(),
        ]);
    }
}
