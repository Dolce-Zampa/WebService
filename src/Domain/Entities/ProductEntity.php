<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Facades\JsonDataStorage;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Traits\ProductBuilder;
use PS\Webservice\Traits\ProductManipulation;

class ProductEntity implements ObjectInterface
{
    use ProductManipulation, ProductBuilder;

    /** @var array<string, mixed> */
    private array $data;
    private Product $service;

    private static $filters = [];

    private function __construct(array $data, PrestashopServiceInterface $service)
    {
        $this->service = $service;
        $this->data = $data;
        $this->normalizeData();
    }

    public static function create(array $data, PrestashopServiceInterface $service): self
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
        } else {
            unset($this->data['associations']['product_option_values']);
            $this->data['url'] = isset($this->data['url']) ? str_replace('https://www.dolcezampa.com', '', $this->data['url']) : null; //FIXME: remove these on production
            // $this->buildImageLink([ImageTail::ORIGINAL]); //FIXME: possiamo rimuovere l'immagine verrà creata tramite FRONTEND
        }

        // normalize on_sale flag
        $originalePrice = round((float)$this->data['original_price'], 2, PHP_ROUND_HALF_UP);
        $currentPrice = round((float)$this->data['price'], 2, PHP_ROUND_HALF_UP);
        $this->data['on_sale'] = $originalePrice < $currentPrice;
    }

    public function withFeatures(): self
    {
        $this->buildCombinations();
        $this->buildProductFeatures();
        $this->buildAccessories();
        $this->buildCategories();
        $this->buildStockAvailables();
        $this->buildBundles();
        $this->buildCustomizations();

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
