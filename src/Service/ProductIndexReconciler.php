<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use Illuminate\Support\Facades\Log;

final class ProductIndexReconciler
{
    public function __construct(private ProductIndexAudit $audit, private RedisQueue $queue) {}

    public function run(): void
    {
        $token = $this->queue->acquireIndexReconcileLock();
        if ($token === null) {
            Log::warning('Elasticsearch product reconciliation already running');
            return;
        }
        try {
            // Do not enqueue anything until both catalog and index have been scanned successfully.
            $report = $this->audit->scan();
            $counts = ['queued' => 0, 'pending' => 0, 'failed' => 0];
            $failedIds = [];
            foreach ($report['missing_products'] as $id => $version) {
                $status = $this->queue->enqueueMissingIndexProduct((int) $id, $version);
                ++$counts[$status];
                if ($status === 'failed') {
                    $failedIds[] = (int) $id;
                }
            }
            Log::info('Elasticsearch product reconciliation completed', [
                'catalog' => $report['active_catalog_count'],
                'indexed' => $report['active_indexed_count'],
                'missing' => count($report['missing_products']),
                'queued' => $counts['queued'],
                'pending' => $counts['pending'],
                'failed' => $counts['failed'],
                'duplicates' => count($report['duplicate_ids']),
                'invalid_documents' => $report['invalid_documents'],
            ]);
            if ($failedIds !== []) {
                Log::warning('Elasticsearch products awaiting investigation', ['product_ids' => $failedIds]);
            }
        } finally {
            $this->queue->releaseIndexReconcileLock($token);
        }
    }
}
