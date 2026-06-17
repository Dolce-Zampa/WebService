<?php
declare(strict_types=1);
namespace PS\Webservice\Traits;

use PS\Webservice\Domain\Enums\ImageTail;
use Illuminate\Support\Facades\Log;


trait ProductBuilder
{
    use UseCache;

    /**
     * Builds association links for the product entity using image service and image tails.
     *
     * @param array $imageTails An array of image tail identifiers or configurations for association
     *
     * @return void
     */
    protected function buildImageLink(array $imageTails = []): void
    {
        $associations = $this->data['associations'];

        if (!isset($associations['images']) || !is_array($associations['images'])) {
            return; // No images to process
        }

        foreach ($associations['images'] as $index => $image) {
            if (!isset($image['id'])) {
                throw new \InvalidArgumentException("Each image association must have an 'id' field");
            }

            if (empty($imageTails)) {
                $imageTails = ImageTail::cases(); // Use all available tails if none are specified
            }

            /** @var ImageTail $tail */
            foreach ($imageTails as $tail) {
                if (!$tail instanceof ImageTail) {
                    throw new \InvalidArgumentException("Image tails must be an array of ImageTail enum values");
                }

                // use caching for image retrieval to optimize performance, as images are often requested multiple times
                $cacheKey = "product_{$this->getId()}_image_{$image['id']}_tail_{$tail->value}";
                $cachedImage = $this->tags(['product-builder'])->getFromCache($cacheKey);
                if ($cachedImage) {
                    $this->data['associations']['images'][$index][$tail->value] = $cachedImage;
                    continue; // Skip retrieval if cached image is available
                }

                $image = $this->service->getSpecificationsImage($this->getId(), (int) $image['id'], $tail);

                if ($image === null) {
                    Log::warning("Image with for product {$this->getId()} could not be retrieved with tail {$tail->value}");
                    continue; // Skip this tail if the image retrieval fails, but keep processing other tails
                }

                $this->tags(['product-builder'])->setToCache($cacheKey, $image->toArray(), (1440 * 364)); // Cache the image data for 24 hours
                $this->data['associations']['images'][$index][$tail->value] = $image->toArray();
            }
        }

    }

    /**
     * Builds full option payloads starting from the option ids in associations.
     *
     * Each value in associations.product_option_values is expected to contain an id,
     * and the corresponding option details are fetched through the Option service.
     *
     * @param Option $option Service used to fetch option value details
     *
     * @throws \RuntimeException When option retrieval fails
     */
    protected function buildOptionValues(): void
    {
        $cacheKey = "product_{$this->getId()}_option_values";
        $cachedOptions = $this->tags(['product-options'])->getFromCache($cacheKey);
        if ($cachedOptions !== null) {
            $this->data['associations'] = $cachedOptions;
            return; // Use cached option values if available
        }

        foreach ($this->getProductOptionValues() as $i => $value) {
            try {
                $optionValue = $this->service->getSpecificationsOption(id: $value['id']);
                $this->data['associations'][] = $optionValue->toArray();
            } catch (\Exception $e) {
                Log::error("Failed to retrieve option value with id {$value['id']} for product {$this->getId()}: " . $e->getMessage());
                continue; // Skip this option value but continue processing others
            }
        }

        $this->tags(['product-options'])->setToCache($cacheKey, $this->data['associations'], (1440 * 364)); // Cache the full option values for 1 year

        unset($this->data['associations']['product_option_values']); // Remove the id-only entry to avoid confusion
    }

    /**
     * Builds product combinations using the provided combination service.
     *
     * This method processes and generates all available combinations for the product
     * based on the logic implemented in the combination service.
     *
     * @return void
     */
    protected function buildCombinations(): void
    {
        $associations = $this->data['associations'];
        if (isset($associations['combinations']) && is_array($associations['combinations'])) {
            foreach ($associations['combinations'] as $i => $combination) {
                if (!isset($combination['id'])) {
                    throw new \InvalidArgumentException("Each combination association must have an 'id' field");
                }

                $combination = $this->service->getSpecificationsCombination((int) $combination['id']);
                $this->data['associations']['combinations'][$i] = $combination->toArray();
            }
        }   

    }

    /**
     * Builds product features using the provided feature service.
     *
     * This method processes and generates all available product features.
     *
     * @return void
     */
    protected function buildProductFeatures(): void
    {
        $associations = $this->data['associations'];
        if (!isset($associations['product_features']) || !is_array($associations['product_features'])) {
            return;
        }

        foreach ($associations['product_features'] as $i => $feature) {
            if (!isset($feature['id']) || !isset($feature['id_feature_value'])) {
                throw new \InvalidArgumentException("Each product_feature association must have 'id' and 'id_feature_value' fields");
            }

            $featureEntity = $this->service->getSpecificationsProductFeature((int) $feature['id'], (int) $feature['id_feature_value']);
            $this->data['associations']['product_features'][$i] = $featureEntity->toArray();
        }
    }

    /**
     * Builds accessories using the provided accessory service.
     *
     * This method processes and generates all available accessories for the product.
     *
     * @return void
     */
    protected function buildAccessories(): void
    {
        $associations = $this->data['associations'];
        if (!isset($associations['accessories']) || !is_array($associations['accessories'])) {
            return;
        }

        foreach ($associations['accessories'] as $i => $accessory) {
            if (!isset($accessory['id'])) {
                throw new \InvalidArgumentException("Each accessory association must have an 'id' field");
            }

            $accessoryEntity = $this->service->getSpecificationsAccessory((int) $accessory['id']);
            $this->data['associations']['accessories'][$i] = $accessoryEntity->toArray();
        }

    }

    /**
     * Builds categories using the provided category service.
     *
     * This method processes and generates all available categories for the product.
     *
     * @return void
     */
    protected function buildCategories(): void
    {
        $associations = $this->data['associations'];
        if (!isset($associations['categories']) || !is_array($associations['categories'])) {
            return;
        }

        foreach ($associations['categories'] as $i => $category) {
            if (!isset($category['id'])) {
                throw new \InvalidArgumentException("Each category association must have an 'id' field");
            }

            $categoryEntity = $this->service->getSpecificationsCategory((int) $category['id']);
            $this->data['associations']['categories'][$i] = $categoryEntity->toArray();
        }

    }

    /**
     * Builds stock availables using the provided stock available service.
     *
     * This method retrieves all stock availability information for the product.
     *
     * @return void
     */
    protected function buildStockAvailables(): void
    {
        $stockAvailables = $this->service->getSpecificationsStockAvailables($this->getId());

        $this->data['associations']['stock_availables'] = [];
        foreach ($stockAvailables as $stockEntity) {
            $this->data['associations']['stock_availables'][] = $stockEntity->toArray();
        }
    }

    /**
     * build Customizations
     * @return void
     */
    protected function buildCustomizations(): void
    {
        $customizations = $this->service->getCustomizationFields($this->getId());

        $this->data['associations']['customizations'] = [];
        foreach ($customizations as $customizationEntity) {
            $this->data['associations']['customizations'][] = $customizationEntity->toArray();
        }
    }

}