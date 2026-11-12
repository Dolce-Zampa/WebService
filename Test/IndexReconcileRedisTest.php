<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Predis\Client;
use PS\Webservice\Service\ElkService;
use PS\Webservice\Service\RedisQueue;

/** Requires INDEX_RECONCILE_TEST_REDIS_SOCKET to point to a dedicated disposable Redis instance. */
final class IndexReconcileRedisTest extends TestCase
{
    private Client $redis;
    private RedisQueue $queue;

    protected function setUp(): void
    {
        $socket = getenv('INDEX_RECONCILE_TEST_REDIS_SOCKET');
        if (!$socket) {
            self::markTestSkipped('No isolated Redis test socket configured');
        }
        $this->redis = new Client(['scheme' => 'unix', 'path' => $socket]);
        $this->redis->flushdb();
        $this->queue = new RedisQueue($this->redis);
    }

    public function test_reservations_retry_suppression_and_catalog_change(): void
    {
        self::assertSame('queued', $this->queue->enqueueMissingIndexProduct(228, 'v1'));
        self::assertSame('pending', $this->queue->enqueueMissingIndexProduct(228, 'v1'));
        $job = json_decode($this->redis->lpop(ElkService::QUEUE_NAME), true);
        self::assertSame([228], $job['product_ids']);
        $job['attempt'] = 5;
        $this->queue->finishIndexReconcileJob($job, true);
        self::assertSame('failed', $this->queue->enqueueMissingIndexProduct(228, 'v1'));
        self::assertSame(1, $this->redis->llen(ElkService::FAILED_QUEUE));
        self::assertSame('queued', $this->queue->enqueueMissingIndexProduct(228, 'v2'));
        $newJob = json_decode($this->redis->lpop(ElkService::QUEUE_NAME), true);
        $this->queue->finishIndexReconcileJob($newJob, false);
        self::assertSame('queued', $this->queue->enqueueMissingIndexProduct(228, 'v1'));
    }

    public function test_lock_and_expired_job_cannot_release_new_reservation(): void
    {
        $token = $this->queue->acquireIndexReconcileLock();
        self::assertNotNull($token);
        self::assertNull($this->queue->acquireIndexReconcileLock());
        $this->queue->releaseIndexReconcileLock('wrong');
        self::assertNull($this->queue->acquireIndexReconcileLock());
        $this->queue->releaseIndexReconcileLock($token);
        self::assertNotNull($this->queue->acquireIndexReconcileLock());

        self::assertSame('queued', $this->queue->enqueueMissingIndexProduct(271, 'v1'));
        $old = json_decode($this->redis->lpop(ElkService::QUEUE_NAME), true);
        $this->redis->del('elk:reconcile:pending:271'); // Simulate a reservation expiring after a worker crash.
        self::assertSame('queued', $this->queue->enqueueMissingIndexProduct(271, 'v2'));
        $this->queue->finishIndexReconcileJob($old, false);
        self::assertSame('pending', $this->queue->enqueueMissingIndexProduct(271, 'v2'));
    }
}
