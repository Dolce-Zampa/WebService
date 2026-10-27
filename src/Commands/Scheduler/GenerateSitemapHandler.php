<?php

declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Commands\GenerateSitemap as GenerateSitemapCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateSitemapHandler
{
    public function __construct(private readonly GenerateSitemapCommand $command)
    {
    }

    public function __invoke(GenerateSitemap $message): void
    {
        $output = new BufferedOutput();
        $exitCode = $this->command->run(new ArrayInput([]), $output);

        Log::info('Scheduler: eseguito sitemap:generate', [
            'exitCode' => $exitCode,
            'output' => $output->fetch(),
        ]);
    }
}
