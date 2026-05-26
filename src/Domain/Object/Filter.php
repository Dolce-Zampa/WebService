<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use PS\Webservice\Traits\UuidGenerator;

final class Filter
{
    use UuidGenerator;

    private array $data;

    const ALLOWED_FILTER = ['on_sale', 'product_option_values', 'id_attribute', 'id_default_combination', 'id_supplier', 'id', 'id_manufacturer', 'id_category_default','price','customizable'];

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->validate();
    }

    public function validate(array $toDecode = []): void
    {
        // check if filter is allowed
        foreach ($this->data as $filterKey => $filterValue) {
            if($filterKey == 'brands') {
                $this->data['id_manufacturer'] = $filterValue;
                unset($this->data['brands']);
                continue;
            }

            if($filterKey == 'sizes') {
                $this->data['associations']['product_option_values'] = $filterValue;
                unset($this->data['sizes']);
                continue;
            }

            if($filterKey == 'colors') {
                $this->data['associations']['product_option_values'] = $filterValue;
                unset($this->data['colors']);
                continue;
            }
        }
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name];
    }

    public function match(array $productData): bool
    {
        if(empty($this->data)) {
            return true; // No filters to apply, so the product matches by default
        }


        $matched = $this->matchRecursive($productData, $this->data);
        return $matched; // Product matches all filter criteria
    }

    private function matchRecursive(array $productData, array $dataValue) 
    {
        
        foreach ($dataValue as $filterKey => $filterValue) {

            if($filterKey === 'on_sale' && $filterValue === true) {
                $originalePrice = round((float)$productData['original_price'], 2, PHP_ROUND_HALF_UP);
                $currentPrice = round((float)$productData['price'], 2, PHP_ROUND_HALF_UP);
                if($originalePrice < $currentPrice) {
                    return true; // Product is not on sale
                }
                continue;
            }

            if(is_array($filterValue)) {
                if(isset($productData[$filterKey]) && is_array($productData[$filterKey])) {
                    if($this->matchRecursive($productData[$filterKey], $filterValue)) {
                        return true;
                    }
                }
                continue; // Skip non-array filters for now
            }

            if(is_int($filterValue) || is_string($filterValue)) {
                $filterValue = (string) $filterValue;
            }

            $filterValues = explode('|',$filterValue);
            if (!in_array($filterKey, self::ALLOWED_FILTER, true)) {
                continue; // Skip unsupported filters
            }

            if(isset($productData[$filterKey]) && is_array($productData[$filterKey])) {
                foreach($productData[$filterKey] as $productValue) {
                    if (in_array($productValue['id'], $filterValues)) {
                        return true; // Product matches the filter criteria
                    }
                }
            } else {
                if (isset($productData[$filterKey]) && in_array($productData[$filterKey], $filterValues)) {
                    return true; // Product does not match the filter criteria
                }
            }
        }

        return false; // Product not matches all filter criteria
    }

    
}