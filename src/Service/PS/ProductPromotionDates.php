<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Models\PS\Products\SpecificPrice;

final class ProductPromotionDates
{
    /** @return array<int, array{start: ?string, end: ?string}> */
    public function forProduct(int $productId, ?\DateTimeImmutable $now = null): array
    {
        return $this->forProducts([$productId], $now)[$productId] ?? [];
    }

    /** @return array<int, array<int, array{start: ?string, end: ?string}>> */
    public function forProducts(array $productIds, ?\DateTimeImmutable $now = null): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id) => $id > 0)));
        if ($productIds === []) {
            return [];
        }
        $timezone = new \DateTimeZone((string) env('APP_TIMEZONE', 'Europe/Rome'));
        $now = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);

        try {
            $prices = SpecificPrice::query()
                ->whereIn('id_product', $productIds)
                ->where('id_cart', 0)
                ->where('id_customer', 0)
                ->where('from_quantity', '<=', 1)
                ->where('from', '<=', $now->format('Y-m-d H:i:s'))
                ->where(function ($query) use ($now) {
                    $query->where('to', '0000-00-00 00:00:00')
                        ->orWhere('to', '>=', $now->format('Y-m-d H:i:s'));
                })
                ->where(function ($query) {
                    $query->where('reduction', '>', 0)->orWhere('price', '>=', 0);
                })
                ->orderByDesc('from')
                ->orderByDesc('id_specific_price')
                ->get(['id_product', 'id_product_attribute', 'from', 'to']);

            $result = [];
            foreach ($prices as $price) {
                $productId = (int) $price->id_product;
                $attribute = (int) $price->id_product_attribute;
                if (!isset($result[$productId][$attribute])) {
                    $result[$productId][$attribute] = [
                        'start' => $price->from === '0000-00-00 00:00:00'
                            ? null : (new \DateTimeImmutable($price->from, $timezone))->format(\DateTimeInterface::ATOM),
                        'end' => $price->to === '0000-00-00 00:00:00'
                            ? null : (new \DateTimeImmutable($price->to, $timezone))->format(\DateTimeInterface::ATOM),
                    ];
                }
            }
            return $result;
        } catch (\Throwable $exception) {
            Log::warning('Unable to retrieve product promotion dates', [
                'product_ids' => $productIds,
                'exception' => $exception->getMessage(),
            ]);
            return [];
        }
    }
}
