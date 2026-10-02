<?php
declare(strict_types=1);
namespace PS\Webservice\Commands\Scheduler;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Commands\WarmProductCacheCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
final class WarmProductCacheMessageHandler
{
    public function __construct(private WarmProductCacheCommand $command) {}
    public function __invoke(WarmProductCacheMessage $message): void
    {
        $output = new BufferedOutput();
        $status = $this->command->run(new ArrayInput([]), $output);
        Log::info('Nightly product cache warmup', ['status' => $status, 'output' => $output->fetch()]);
    }
}
