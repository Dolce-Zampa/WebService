<?php
declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\GiftCard\GiftCardApiException;
use PS\Webservice\Service\GiftCard\GiftCardClient;

final class GiftCardMaintenanceMessageHandler
{
    private bool $configurationWarningLogged = false;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $url,
        private readonly string $key,
        private readonly bool $verifyTls = true,
    ) {}

    public function __invoke(GiftCardMaintenanceMessage $message): void
    {
        if ($this->url === '' || $this->key === '') {
            if (!$this->configurationWarningLogged) {
                Log::warning('Gift card maintenance disabled: API URL or key is not configured.');
                $this->configurationWarningLogged = true;
            }
            return;
        }

        try {
            $result = $this->runMaintenance();
            $sent = $result['emails_sent'] ?? 0;
            $expired = $result['expired_reservations'] ?? 0;
            if ((int) $sent > 0 || (int) $expired > 0) {
                Log::info('Gift card maintenance completed.', ['emails_sent' => (int) $sent, 'expired_reservations' => (int) $expired]);
            }
        } catch (\Throwable $error) {
            // Never log exception messages or responses, which can contain secrets.
            Log::error('Gift card maintenance failed; the scheduler will retry.', [
                'error_code' => $error instanceof GiftCardApiException ? $error->errorCode : 'configuration_or_transport_error',
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function runMaintenance(): array
    {
        if ($this->url === '' || $this->key === '') {
            throw new \InvalidArgumentException('Configure GIFT_CARD_API_URL and GIFT_CARD_MAINTENANCE_KEY or GIFT_CARD_API_KEY.');
        }
        return (new GiftCardClient($this->http, $this->url, $this->key, $this->verifyTls))->maintenance();
    }
}
