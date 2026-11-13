<?php
declare(strict_types=1);
namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\PickupPoints\InpostCatalog;

final class SyncInpostPointsMessageHandler
{
    public function __construct(private readonly InpostCatalog $catalog, private readonly bool $enabled) {}

    public function __invoke(SyncInpostPointsMessage $message): void
    {
        if (!$this->enabled) { return; }
        try {
            Log::info('InPost catalog synchronized.', ['points' => $this->catalog->sync()]);
        } catch (\Throwable $error) {
            Log::error('InPost catalog synchronization failed.', ['error_type' => get_class($error)]);
        }
    }
}
