<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Entities\CarrierEntity;
use Illuminate\Support\Collection;

class Carrier extends PrestashopService implements PrestashopServiceInterface {

    /**
     * Retrieves a list of active carriers.
     *
     * @param array $displayOptions Optional display options for the API request
     * @return Collection The collection of carrier entities
     */
    public function carriersList(array $displayOptions = ['display' => 'full']): Collection
    {

        // Add filter for active carriers
        if (!isset($displayOptions['filter[active]'])) {
            $displayOptions['filter[active]'] = 1;
            $displayOptions['filter[deleted]'] = 0;
        }

        $queryString = http_build_query($displayOptions);
        $this->httpService->setUrl('/carriers' . ($queryString !== '' ? "?{$queryString}" : ''));

        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new \RuntimeException("Failed to retrieve carriers: " . $response->getHttpCode());
        }

        $collection = new Collection();
        $carriers = $response->toArray()['carriers'] ?? [];
        
        foreach ($carriers as $carrierData) {
            $collection->push(CarrierEntity::create($carrierData, $this));
        }

        return $collection;
    }

    /**
     * Retrieves detailed information about a specific carrier.
     *
     * @param int $carrierId The ID of the carrier to retrieve
     * @return CarrierEntity|null The carrier entity or null if not found
     */
    public function getCarrierDetail(int $carrierId): ?CarrierEntity
    {
        $this->httpService->setUrl("/carriers/{$carrierId}?display=full");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            if ($response->getHttpCode() === 404) {
                return null; // Carrier not found
            }
            throw new \RuntimeException("Failed to retrieve carrier detail: " . $response->getHttpCode());
        }

        $carrierData = $response->toArray()['carriers'][0] ?? null;
        
        if (!$carrierData) {
            return null;
        }

        return CarrierEntity::create($carrierData, $this);
    }

    /**
     * Retrieves carriers available for a specific cart/zone.
     *
     * @param int $idCart The cart ID
     * @param int|null $idZone Optional zone ID
     * @return Collection The collection of available carrier entities
     */
    public function getAvailableCarriers(int $idCart, ?int $idZone = null): Collection
    {
        $filters = [
            'display' => 'full',
            'filter[active]' => 1,
            'filter[deleted]' => 0
        ];

        if ($idZone !== null) {
            $filters['filter[id_zone]'] = $idZone;
        }

        return $this->carriersList($filters);
    }

    private function getCarrierConfigurations(): array
    {
        $configurations = file_get_contents(storage_path('configs/carrier.json'));

        if (empty($configurations)) {
            throw new \RuntimeException("Failed to retrieve carrier configurations: ");
        }

        return json_decode($configurations, true);
    }
}
