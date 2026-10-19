<?php

declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Commands\RetryPendingStripeConnectTransfersCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class RetryPendingStripeConnectTransfersMessageHandler
{
    public function __construct(private readonly RetryPendingStripeConnectTransfersCommand $command)
    {
    }

    public function __invoke(RetryPendingStripeConnectTransfersMessage $message): void
    {
        $output = new BufferedOutput();
        $exitCode = $this->command->run(new ArrayInput([]), $output);

        Log::info('Scheduler: Stripe Connect pending-transfer retry completed.', [
            'exit_code' => $exitCode,
            'output' => $output->fetch(),
        ]);
    }
}
