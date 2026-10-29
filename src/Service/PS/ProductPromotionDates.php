<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Models\PS\Products\SpecificPrice;

final class ProductPromotionDates
{
    /** @return array<int, array{start: string, end: string}> */
    public function forProduct(int $productId, ?\DateTimeImmutable $now = null): array
    {
        $timezone = new \DateTimeZone((string) env('APP_TIMEZONE', 'Europe/Rome'));
        $now = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $today = $now->setTime(0, 0);

        try {
            $prices = SpecificPrice::query()
                ->where('id_product', $productId)
                ->where('id_cart', 0)
                ->where('id_customer', 0)
                ->where('from_quantity', '<=', 1)
                ->where('from', '>=', $today->format('Y-m-d H:i:s'))
                ->where('from', '<=', $now->format('Y-m-d H:i:s'))
                ->where('to', '>=', $now->format('Y-m-d H:i:s'))
                ->where(function ($query) {
                    $query->where('reduction', '>', 0)->orWhere('price', '>=', 0);
                })
                ->orderByDesc('from')
                ->orderByDesc('id_specific_price')
                ->get(['id_product_attribute', 'from', 'to']);

            $result = [];
            foreach ($prices as $price) {
                $attribute = (int) $price->id_product_attribute;
                if (!isset($result[$attribute])) {
                    $result[$attribute] = [
                        'start' => (new \DateTimeImmutable($price->from, $timezone))->format(\DateTimeInterface::ATOM),
                        'end' => (new \DateTimeImmutable($price->to, $timezone))->format(\DateTimeInterface::ATOM),
                    ];
                }
            }
            return $result;
        } catch (\Throwable $exception) {
            Log::warning('Unable to retrieve product promotion dates', [
                'product_id' => $productId,
                'exception' => $exception->getMessage(),
            ]);
            return [];
        }
    }
}
