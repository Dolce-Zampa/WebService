<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Collection;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Entities\ReviewEntity;

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
            $reviewEntities[] = ReviewEntity::create((array) $reviewData, $this);
        }

        return $reviewEntities ?? [];
        
    }
}
