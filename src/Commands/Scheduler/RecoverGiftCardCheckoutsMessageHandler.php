<?php
declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use Illuminate\Support\Facades\Log;
use Psr\Container\ContainerInterface;
use PS\Webservice\Service\GiftCard\GiftCardSettlementService;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;

final class RecoverGiftCardCheckoutsMessageHandler
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function __invoke(RecoverGiftCardCheckoutsMessage $message): void
    {
        if (!filter_var(env('GIFT_CARD_CHECKOUT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        $snapshots = $this->container->get(CheckoutSnapshotService::class);
        $settlement = $this->container->get(GiftCardSettlementService::class);
        foreach ($snapshots->unsettledGiftCardSnapshotIds() as $id) {
            try {
                $settlement->settle($id);
            } catch (\Throwable $error) {
                $snapshots->postponeGiftCardRecovery($id);
                Log::error('Gift card checkout recovery failed; retry scheduled.', [
                    'snapshot_id' => $id, 'exception_type' => get_class($error),
                ]);
            }
        }
    }
}
