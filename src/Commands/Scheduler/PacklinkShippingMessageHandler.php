<?php

declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use GuzzleHttp\ClientInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\Shipping\PacklinkClient;
use PS\Webservice\Service\Shipping\PacklinkDraftFactory;
use PS\Webservice\Service\Shipping\PrestashopShippingClient;
use PS\Webservice\Service\Shipping\ShipmentCreationWorker;
use PS\Webservice\Service\Shipping\ShipmentDraftCreator;
use PS\Webservice\Service\Shipping\ShipmentSyncWorker;

final class PacklinkShippingMessageHandler
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ClientInterface $http)
    {
    }

    public function __invoke(PacklinkShippingMessage $message): void
    {
        if (!filter_var(env('PACKLINK_SHIPPING_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        try {
            $settings = json_decode((string) env('PACKLINK_SHIPPING_SETTINGS', ''), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($settings)) {
                throw new \InvalidArgumentException('Invalid shipping settings.');
            }
            $provider = new PacklinkClient($this->http, (string) env('PACKLINK_API_URL', 'https://api.packlink.com'), (string) env('PACKLINK_API_KEY', ''));
            $shop = new PrestashopShippingClient($this->http, (string) env('PS_BASE_URL', ''), (string) env('WEBSERVICE_KEY', ''), (string) env('PACKLINK_SHOP_SECRET', ''));
        } catch (\Throwable $exception) {
            Log::error('Packlink shipping configuration is invalid.');
            return;
        }
        try {
            $creator = new ShipmentDraftCreator($this->db, $provider);
            $creation = new ShipmentCreationWorker($this->db, $shop, $creator, new PacklinkDraftFactory(), $settings);
            Log::info('Packlink draft worker completed.', $creation->run());
        } catch (\Throwable $exception) {
            Log::error('Packlink draft worker failed.');
        }
        try {
            $sync = new ShipmentSyncWorker($this->db, $provider, $shop, $settings['states'] ?? [], $settings['eligible_states'] ?? [], (int) ($settings['sync_interval_seconds'] ?? 900));
            Log::info('Packlink synchronization worker completed.', $sync->run());
        } catch (\Throwable $exception) {
            Log::error('Packlink synchronization worker failed.');
        }
    }
}
