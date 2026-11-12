<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use Illuminate\Support\Facades\Log;

final class ElkJobHandler
{
    public function __construct(private ElkService $elk, private RedisQueue $queue) {}

    /** Returns false after a failure so the worker can apply its backoff. */
    public function handle(array $payload): bool
    {
        try {
            if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
                $this->elk->bulkIndexProducts($payload['product_ids']);
            }
            if (isset($payload['category_id']) && is_int($payload['category_id'])) {
                $this->elk->bulkIndexCategory($payload['category_id']);
            }
            if (isset($payload['reconcile'])) {
                $this->queue->finishIndexReconcileJob($payload, false);
            }
            Log::info('ElastichWorker: successfully processed job', ['payload' => $payload]);
            return true;
        } catch (\Throwable $e) {
            $attempt = (int) ($payload['attempt'] ?? 0) + 1;
            Log::error('ElastichWorker: failed to process job', ['payload' => $payload, 'attempt' => $attempt, 'error' => $e->getMessage()]);
            $payload['attempt'] = $attempt;
            if ($attempt < 5) {
                $this->queue->push(ElkService::QUEUE_NAME, $payload);
            } else {
                $payload['error'] = $e->getMessage();
                if (isset($payload['reconcile'])) {
                    $this->queue->finishIndexReconcileJob($payload, true);
                } else {
                    $this->queue->push(ElkService::FAILED_QUEUE, $payload);
                }
            }
            return false;
        }
    }
}
