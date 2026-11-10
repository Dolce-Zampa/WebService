<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use Predis\Client as PredisClient;

/**
 * Minimal Redis-backed job queue using Predis.
 * No Laravel/Illuminate Queue dependency required.
 */
class RedisQueue
{
    private const RECONCILE_LOCK = 'elk:reconcile:lock';
    private const RECONCILE_PENDING = 'elk:reconcile:pending:';
    private const RECONCILE_FAILED = 'elk:reconcile:failed:';
    private PredisClient $redis;

    public function __construct(PredisClient $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Push a job payload onto the tail of the given queue.
     *
     * @param string $queue   Queue name (Redis list key)
     * @param array  $payload Associative array to be JSON-encoded
     */
    public function push(string $queue, array $payload): void
    {
        $this->redis->rpush($queue, [json_encode($payload)]);
    }

    public function acquireIndexReconcileLock(): ?string
    {
        $token = bin2hex(random_bytes(16));
        $acquired = $this->redis->eval("return redis.call('SET', KEYS[1], ARGV[1], 'EX', 7200, 'NX') and 1 or 0", 1, self::RECONCILE_LOCK, $token);
        return (int) $acquired === 1 ? $token : null;
    }

    public function releaseIndexReconcileLock(string $token): void
    {
        $this->redis->eval("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0", 1, self::RECONCILE_LOCK, $token);
    }

    /** Atomically reserve and enqueue one missing product; suppress repeated failures for the same catalog version. */
    public function enqueueMissingIndexProduct(int $productId, string $version): string
    {
        $token = bin2hex(random_bytes(16));
        $payload = json_encode(['product_ids' => [$productId], 'reconcile' => ['id' => $productId, 'version' => $version, 'token' => $token]], JSON_THROW_ON_ERROR);
        $script = <<<'LUA'
if redis.call('GET', KEYS[3]) == ARGV[1] then return -1 end
if redis.call('EXISTS', KEYS[2]) == 1 then return 0 end
redis.call('SET', KEYS[2], ARGV[2], 'EX', 172800)
redis.call('RPUSH', KEYS[1], ARGV[3])
return 1
LUA;
        $result = (int) $this->redis->eval($script, 3, ElkService::QUEUE_NAME, self::RECONCILE_PENDING . $productId, self::RECONCILE_FAILED . $productId, $version, $token, $payload);
        return $result === 1 ? 'queued' : ($result === -1 ? 'failed' : 'pending');
    }

    /** Keep a failed job for inspection and avoid retrying the same version for seven days. */
    public function finishIndexReconcileJob(array $payload, bool $failed): void
    {
        $reconcile = $payload['reconcile'] ?? [];
        $id = (int) ($reconcile['id'] ?? 0);
        $token = (string) ($reconcile['token'] ?? '');
        if ($id <= 0 || $token === '') {
            throw new \InvalidArgumentException('Invalid index reconciliation job');
        }
        $script = <<<'LUA'
if ARGV[3] == '1' then redis.call('RPUSH', KEYS[3], ARGV[4]) end
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
if ARGV[3] == '1' then
    redis.call('SET', KEYS[2], ARGV[2], 'EX', 604800)
else
    redis.call('DEL', KEYS[2])
end
redis.call('DEL', KEYS[1])
return 1
LUA;
        $this->redis->eval($script, 3, self::RECONCILE_PENDING . $id, self::RECONCILE_FAILED . $id, ElkService::FAILED_QUEUE, $token, (string) ($reconcile['version'] ?? ''), $failed ? '1' : '0', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Blocking pop from the head of one or more queues.
     * Returns the decoded payload array, or null on timeout.
     *
     * @param string[] $queues  List of queue names to watch (in priority order)
     * @param int      $timeout Seconds to block; 0 = block indefinitely
     * @return array{queue: string, payload: array}|null
     */
    public function pop(array $queues, int $timeout = 10): ?array
    {
        $result = $this->redis->blpop($queues, $timeout);

        if (empty($result)) {
            return null;
        }

        return [
            'queue'   => (string) $result[0],
            'payload' => $this->decodePayload((string) $result[1]),
        ];
    }

    /**
     * @throws \RuntimeException on JSON decode failure
     */
    private function decodePayload(string $json): array
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException('RedisQueue: failed to decode job payload – ' . json_last_error_msg());
        }

        return $data;
    }
}
