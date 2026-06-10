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
            'payload' => (array) json_decode((string) $result[1], true),
        ];
    }
}
