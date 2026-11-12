<?php
declare(strict_types=1);
namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\ProductIndexReconciler;

final class ReconcileProductIndexMessageHandler
{
    public function __construct(private ProductIndexReconciler $reconciler) {}

    public function __invoke(ReconcileProductIndexMessage $message): void
    {
        try {
            $this->reconciler->run();
        } catch (\Throwable $e) {
            Log::error('Elasticsearch product reconciliation failed', ['error' => $e->getMessage()]);
        }
    }
}
