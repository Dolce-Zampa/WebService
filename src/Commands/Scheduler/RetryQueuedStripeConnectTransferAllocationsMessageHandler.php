<?php

declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Commands\RetryQueuedStripeConnectTransferAllocationsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RetryQueuedStripeConnectTransferAllocationsMessageHandler
{
    public function __construct(private readonly RetryQueuedStripeConnectTransferAllocationsCommand $command)
    {
    }

    public function __invoke(RetryQueuedStripeConnectTransferAllocationsMessage $message): void
    {
        $output = new BufferedOutput();
        $exitCode = $this->command->run(new ArrayInput([]), $output);
        Log::info('Scheduler: Stripe Connect allocation retry completed.', [
            'exit_code' => $exitCode,
            'output' => $output->fetch(),
        ]);
    }
}
