<?php
declare(strict_types=1);
namespace PS\Webservice\Commands\Scheduler;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

final class ScheduledPromotionCacheMessageHandler
{
    public function __construct(private Client $client, private string $baseUrl, private string $key) {}

    public function __invoke(ScheduledPromotionCacheMessage $message): void
    {
        try {
            if ($this->baseUrl === '' || $this->key === '') {
                throw new \RuntimeException('PS_BASE_URL or WEBSERVICE_KEY is missing.');
            }
            $response = $this->client->post(rtrim($this->baseUrl, '/') . '/index.php', [
                'query' => ['fc' => 'module', 'module' => 'webserviceapi', 'controller' => 'scheduledpromotioncache'],
                'headers' => ['X-WS-Key' => $this->key, 'Accept' => 'application/json'],
                'timeout' => 300,
                'connect_timeout' => 10,
            ]);
            $body = json_decode((string) $response->getBody(), true);
            if (!is_array($body) || ($body['success'] ?? false) !== true) {
                throw new \RuntimeException('Invalid response from promotion cache endpoint.');
            }
            if (($body['data']['products_processed'] ?? 0) > 0) {
                Log::info('Scheduled promotion cache scan completed', ['products_processed' => $body['data']['products_processed']]);
            }
        } catch (\Throwable $e) {
            // The module retains its checkpoint, so the next scheduled run retries.
            Log::error('Scheduled promotion cache scan failed', ['error' => $e->getMessage()]);
        }
    }
}
