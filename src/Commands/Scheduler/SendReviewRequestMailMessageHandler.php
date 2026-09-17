<?php

declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Commands\SendReviewRequestMailCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendReviewRequestMailMessageHandler
{
    public function __construct(private readonly SendReviewRequestMailCommand $command)
    {
    }

    public function __invoke(SendReviewRequestMailMessage $message): void
    {
        $output = new BufferedOutput();
        $exitCode = $this->command->run(new ArrayInput([]), $output);

        Log::info('Scheduler: eseguito app:orders:send-review-request', [
            'exitCode' => $exitCode,
            'output' => $output->fetch(),
        ]);
    }
}
