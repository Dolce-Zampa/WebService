<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\AccessoryEntity;
use PS\Webservice\Domain\Entities\CategoryEntity;
use PS\Webservice\Domain\Entities\CombinationEntity;
use PS\Webservice\Domain\Entities\CustomizationEntity;
use PS\Webservice\Domain\Entities\ImageEntity;
use PS\Webservice\Domain\Entities\OptionEntity;
use PS\Webservice\Domain\Entities\ProductFeatureEntity;
use PS\Webservice\Domain\Entities\ReviewEntity;
use PS\Webservice\Domain\Entities\StockAvailableEntity;
use PS\Webservice\Domain\Enums\ImageTail;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

class PrestashopService implements PrestashopServiceInterface {

    protected HttpServiceInterface $httpService;
    protected PrestashopRepository $productRepository;
    
    public function __construct(HttpServiceInterface $httpService) {
        $this->httpService = $httpService;
    }

    //FIXME:
    public function addRepository(PrestashopRepository $repository): void {
        $this->productRepository = $repository;
    }


    /**
     * Retrieves the image URL for a specific product image.
     *
     * @param int $productId The unique identifier of the product
     * @param int $imageId The unique identifier of the image
     * @param ImageTail $type The type/tail specification for the image
     *
     * @return ImageEntity|null The image entity containing the URL and related information, or null if retrieval fails
     */
    public function getSpecificationsImage(int $productId, int $imageId, ImageTail $type): ?ImageEntity 
    {
        $this->httpService->setUrl("/images/products/{$productId}/{$imageId}/{$type->value}");

        try {
            $response = $this->httpService->invoke('GET');
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving image for product {$productId} with image ID {$imageId} and type {$type->value}: " . $e->getMessage());
            return null;
        }
        return ImageEntity::create($response->toArray(), $this);
    }

    /**
     * Retrieves the option details for a specific combination.
     *
     * @param int|null $id The unique identifier of the product
     *
     * @return OptionEntity The option entity containing the details of the option
     */
    public function getSpecificationsOption(int $id): OptionEntity 
    {
        $this->httpService->setUrl("/product_option_values/{$id}?display=full");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve option: " . $response->getHttpCode());
        }
        return OptionEntity::create($response->toArray()['product_option_values'][0], $this);

    }

    /**
     * Retrieves the combination details for a specific combination.
     *
     * @param int $id The unique identifier of the combination
     *
     * @return CombinationEntity The combination entity containing the details of the combination
     */
    public function getSpecificationsCombination(int $id): CombinationEntity 
    {
        $this->httpService->setUrl("/combinations/{$id}?display=full");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve combination: " . $response->getHttpCode());
        }

        foreach($response->toArray()['combinations'] as $combinationData) {
            $combination = CombinationEntity::create($combinationData, $this);
        }

        return $combination;
    }

    /**
     * Retrieves the product feature details.
     *
     * @param int $id The unique identifier of the product feature
     * @param int $featureValueId The unique identifier of the feature value
     *
     * @return ProductFeatureEntity The product feature entity containing the details
     */
    public function getSpecificationsProductFeature(int $id, int $featureValueId): ProductFeatureEntity 
    {
        $this->httpService->setUrl("/product_feature_values/{$featureValueId}?display=full");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve product feature: " . $response->getHttpCode());
        }

        $value = $response->toArray()['product_feature_values'][0];
        $featureValue = ProductFeatureEntity::create($value, $this);

        return $featureValue;
    }

    /**
     * Retrieves the accessory product details.
     *
     * @param int $id The unique identifier of the accessory product
     *
     * @return AccessoryEntity The accessory entity containing the details
     */
    public function getSpecificationsAccessory(int $id): AccessoryEntity 
    {
        $this->httpService->setUrl("/products/{$id}?display=full");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve accessory: " . $response->getHttpCode());
        }

        $accessoryData = [
            'id' => $id,
        ];

        return AccessoryEntity::create($accessoryData, $this);
    }

    /**
     * Retrieves the category details.
     *
     * @param int $id The unique identifier of the category
     *
     * @return CategoryEntity The category entity containing the details
     */
    public function getSpecificationsCategory(int $id): CategoryEntity 
    {
        $this->httpService->setUrl("/categories/{$id}?display=full");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve category: " . $response->getHttpCode());
        }

        $categoryData = $response->toArray()['categories'][0] ?? [];
        
        return CategoryEntity::create($categoryData, $this);
    }

    /**
     * Retrieves the stock available details for a product.
     *
     * @param int $productId The unique identifier of the product
     *
     * @return array Array of StockAvailableEntity objects
     */
    public function getSpecificationsStockAvailables(int $productId): array 
    {
        $this->httpService->setUrl("/stock_availables?filter[id_product]={$productId}&display=full");
        $response = $this->httpService->invoke('GET');

        if($response->failed()) {
            throw new \RuntimeException("Failed to retrieve stock availables: " . $response->getHttpCode());
        }

        $stockAvailables = [];
        $stockData = $response->toArray()['stock_availables'] ?? [];
        
        foreach ($stockData as $stock) {
            $stockAvailables[] = StockAvailableEntity::create($stock, $this);
        }
        
        return $stockAvailables;
    }


    /**
     * Retrieves the customization fields for a specific product.
     * @param int $idProduct The unique identifier of the product
     * @return array An array of customization fields associated with the product
     */
    public function getCustomizationFields(int $idProduct): array
    {
        $this->httpService->setUrl("/product_customization_fields?filter[id_product]=$idProduct&display=full");
        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            Log::error("Failed to retrieve customization fields for product ID {$idProduct}: HTTP " . $response->getHttpCode());
            return [];
        }

        foreach ($response->toArray()['customization_fields'] ?? [] as $customizationData) {
            $customizationFields[] = CustomizationEntity::create($customizationData, $this);
        }

        return $customizationFields ?? [];
    }

    public function getProductReviews(int $productId): array {

        try {
            $reviews = $this->productRepository->getProductReviews($productId);
        } catch (\Exception $e) {
            Log::critical("Failed to retrieve reviews for product ID {$productId}: " . $e->getMessage());
            return [];
        }

        foreach ($reviews as $reviewData) {
            $reviewEntities[] = ReviewEntity::create((array) $reviewData, $this);
        }

        return $reviewEntities ?? [];

    }

}