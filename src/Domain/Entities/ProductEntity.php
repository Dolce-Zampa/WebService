<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Facades\JsonDataStorage;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\ProductBuilder;
use PS\Webservice\Traits\ProductManipulation;

class ProductEntity extends Entity implements ObjectInterface
{
    use ProductManipulation, ProductBuilder;

    /** @var array<string, mixed> */
    protected array $data;
    protected ?PrestashopServiceInterface $service;

    protected $tagsCache = 'product:';

    private $filters = [];

    protected $cacheTTL = null; // Cache TTL in minutes, null means no expiration

    public static function create(array $data, ?PrestashopServiceInterface $service): self
    {
        return new self($data, $service);
    }

    public function getId(): int
    {
        return (int) $this->data['id'];
    }

    public function getName(): string
    {
        return (string) $this->data['name'];
    }

    public function getDescription(): string
    {
        return (string) $this->data['description'];
    }

    public function getPrice(): float
    {
        return (float) $this->data['price'];
    }

    public function toArray(): array
    {
        $this->calculateFullPrice(); // Ensure the price is calculated before converting to array
        return $this->data;
    }

    public function getImages(): array
    {
        return $this->data['associations']['images'] ?? [];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            throw new \InvalidArgumentException('No argument found with ' . $name);
        }

        return $this->data[$name];
    }

    public function normalizeData(): void
    {
        if (!empty($this->data['filters'])) {
            foreach ($this->data as $key => $value) {
                if (in_array($key, $this->filters)) {
                    $this->data[$key] = $value;
                } else {
                    unset($this->data[$key]);
                }
            }
        }

        //normalize urls
        if(!is_null($this->data['url'])) {
            $this->data['url'] = str_replace('http://aidyis-prod-backoffice.dolcezampa.com', '', $this->data['url']);
        }

        // normalize on_sale flag
        $originalePrice = round((float)$this->data['original_price'], 2, PHP_ROUND_HALF_UP);
        $currentPrice = round((float)$this->data['price'], 2, PHP_ROUND_HALF_UP);
        $this->data['on_sale'] = $originalePrice < $currentPrice;
        $this->data['shipping_cost'] = '6.99'; //FIXME: remove this on production, shipping cost will be calculated on checkout

    }

    // una combinazione se ha il valore di price > 0 significa che ha un prezzo incrementale quindi non è in promozione.
    // se invece il price = 0 non ha prezzo incrementale quindi se original_price è diverso da base price allora è in promozione
    // @deprecated non è performante
    private function checkIfCombinationProductHasPromotion(): void
    {
        if (!empty($this->data['associations']['combinations'])) {
            foreach ($this->data['associations']['combinations'] as $combination) {
                if($combination['price'] == 0) {
                    $originalePrice = round((float)$combination['original_price'], 2, PHP_ROUND_HALF_UP);
                    $currentPrice = round((float)$this->data['price'], 2, PHP_ROUND_HALF_UP);
                    if ($originalePrice < $currentPrice) {
                        $this->data['on_sale'] = true;
                        $this->data['original_price'] = $originalePrice;
                        return;
                    }
                }
            }
        }
    }

    public function withCombinations(): self
    {
        $this->buildCombinations();
        // $this->checkIfCombinationProductHasPromotion();
        return $this;
    }

    public function withProductFeatures(): self
    {
        $this->buildProductFeatures();
        return $this;
    }

    public function withAccessories(): self
    {
        $this->buildAccessories();
        return $this;
    }

    public function withCategories(): self
    {
        $this->buildCategories();
        return $this;
    }

    public function withStockAvailables(): self
    {
        $this->buildStockAvailables();
        return $this;
    }

    public function withCustomizations(): self
    {
        $this->buildCustomizations();
        return $this;
    }

    public function withReviews(): self
    {
        $this->buildReviews();
        return $this;
    }

    public function withFeatures(): self
    {
        $this->withCombinations();
        $this->buildProductFeatures();
        $this->buildAccessories();
        $this->buildCategories();
        $this->buildStockAvailables();
        $this->buildBundles();
        $this->buildCustomizations();
        $this->buildReviews();

        return $this;
    }

    private function buildBundles(): void
    {
        $bundles = JsonDataStorage::productBundles()->createQuery()->where('product_id', (string) $this->getId())->fetch();
        if (!empty($bundles)) {
            foreach ($bundles as $bundle) {
                foreach ($bundle['bundle'] as $item) {
                    try {
                        $bundleFound = $this->service->getProductById((int) $item['product_id']);
                        if ($bundleFound === null) {
                            Log::warning("Bundle product with ID {$item['product_id']} not found for product ID {$this->getId()}");
                            continue;
                        }
                        $bundleFound = $bundleFound->toArray();
                        $bundleFound['bundle_reduction'] = $item['reduction'];
                        $bundleFound['bundle_reduction_type'] = $item['reduction_type'];
                        $this->data['bundles'][] = $bundleFound;
                    } catch (\Exception $e) {
                        Log::error("Error retrieving bundle product with ID {$item['product_id']} for product ID {$this->getId()}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
    {
        return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray());
    }

    public function addFiler(FilterEntity $filter): void
    {
        $this->data['filters'][] = $filter->toArray();
    }
}
