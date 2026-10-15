<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Entities\ReviewEntity;
use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;

class Brand extends PrestashopService implements PrestashopServiceInterface
{
    public function brandsList(array $displayOptions = ['display' => 'full']): Collection
    {
        if (!empty($displayOptions)) {
            $queryString = http_build_query($displayOptions);
            $this->httpService->setUrl("/manufacturers?{$queryString}&filter[active]=1");
        } else {
            $this->httpService->setUrl('/manufacturers');
        }

        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to retrieve categories: ' . $response->getHttpCode());
        }

        $collection = new Collection();
        foreach (($response->toArray()['manufacturers'] ?? []) as $categoryData) {
            $collection->push(ManufactureEntity::create($categoryData, $this));
        }

        return $collection;
    }

    public function getReviews(int $id): array
    {
        try {
            $reviews = $this->productRepository->getManufacturertReviews($id);
        } catch (\Exception $e) {
            Log::critical("Failed to retrieve reviews for product ID {$id}: " . $e->getMessage());
            return [];
        }

        foreach ($reviews as $reviewData) {
            $reviewEntities[] = ReviewEntity::create((array) $reviewData, null)->toArray();
        }

        return $reviewEntities ?? [];
    }

    /**
     * Return active manufacturers ranked by paid sales in the current calendar month.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthlyTop(int $limit): array
    {
        $query = Manufacturer::query();
        $detailAlias = $query->getQuery()->getConnection()->getTablePrefix() . 'detail';

        $manufacturerIds = $query
            ->from('manufacturer as manufacturer')
            ->join('product as product', 'product.id_manufacturer', '=', 'manufacturer.id_manufacturer')
            ->join('order_detail as detail', 'detail.product_id', '=', 'product.id_product')
            ->join('orders as orders', 'orders.id_order', '=', 'detail.id_order')
            ->join('order_state as state', 'state.id_order_state', '=', 'orders.current_state')
            ->where('manufacturer.active', 1)
            ->where('state.paid', 1)
            ->where('orders.date_add', '>=', Carbon::now()->startOfMonth())
            ->groupBy('manufacturer.id_manufacturer')
            ->orderByRaw("SUM({$detailAlias}.total_price_tax_incl) DESC")
            ->orderByRaw("SUM({$detailAlias}.product_quantity) DESC")
            ->orderBy('manufacturer.id_manufacturer')
            ->limit($limit)
            ->pluck('manufacturer.id_manufacturer')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($manufacturerIds === []) {
            return [];
        }

        $manufacturers = Manufacturer::query()
            ->with('details')
            ->whereIn('id_manufacturer', $manufacturerIds)
            ->get()
            ->keyBy('id_manufacturer');

        $rankedManufacturers = [];
        foreach ($manufacturerIds as $manufacturerId) {
            $manufacturer = $manufacturers->get($manufacturerId);
            if ($manufacturer === null) {
                continue;
            }

            $rankedManufacturers[] = [
                'id' => (int) $manufacturer->id_manufacturer,
                'name' => (string) $manufacturer->name,
                'slug' => $manufacturer->link_rewrite,
                'image' => $manufacturer->details?->avatar,
                'active' => (bool) $manufacturer->active,
            ];
        }

        return $rankedManufacturers;
    }
}
