<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Entities\CarrierEntity;
use Illuminate\Support\Collection;

class Carrier extends Product implements PrestashopServiceInterface {

    public function shippingPolicy(): array
    {
        $this->httpService->setModuleController('shippingpolicy');
        $response = $this->httpService->invoke('GET');
        $result = $response->toArray();
        $threshold = $result['data']['free_shipping_threshold'] ?? null;
        if ($response->failed() || !is_numeric($threshold) || !is_finite((float) $threshold) || (float) $threshold < 0) {
            throw new \RuntimeException('Shipping policy unavailable.');
        }
        return ['free_shipping_threshold' => (float) $threshold];
    }

    /**
     * Retrieves a list of active carriers.
     *
     * @param array $displayOptions Optional display options for the API request
     * @return Collection The collection of carrier entities
     */
    public function carriersWithFixedRates(): Collection
    {
        $this->httpService->setModuleController('carrierrates');

        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new \RuntimeException('PrestaShop returned HTTP ' . $response->getHttpCode());
        }

        $collection = new Collection();
        $data = $response->toArray();
        $carriers = $data['data']['carriers'] ?? null;
        if (!is_array($carriers)) throw new \RuntimeException('Invalid carrier rates response.');
        
        foreach ($carriers as $carrierData) {
            $collection->push(CarrierEntity::create($carrierData, $this));
        }

        return $collection;
    }

    public function carriersList(array $displayOptions = ['display' => 'full']): Collection
    {
        if (!isset($displayOptions['filter[active]'])) {
            $displayOptions['filter[active]'] = 1;
            $displayOptions['filter[deleted]'] = 0;
        }
        $queryString = http_build_query($displayOptions);
        $this->httpService->setUrl('/carriers' . ($queryString !== '' ? "?{$queryString}" : ''));
        $response = $this->httpService->invoke('GET');
        if ($response->failed()) throw new \RuntimeException('Failed to retrieve carriers: ' . $response->getHttpCode());
        $collection = new Collection();
        foreach ($response->toArray()['carriers'] ?? [] as $carrierData) {
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
