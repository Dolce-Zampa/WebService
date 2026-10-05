<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

/**
* {
*  "cart": {
*    "id_customer": 12,
*    "id_currency": 1,
*    "id_lang": 1,
*    "id_carrier": 2,
*    "replace_products": false,
*    "id_address_delivery": 34,
*    "id_address_invoice": 34,
*    "products": [
*      {
*        "id_product": 10,
*        "id_product_attribute": 0,
*        "quantity": 2
*      }
*    ]
*  }
* }
*/

class CartEntity implements ObjectInterface
{
	use UuidGenerator;

	/** @var array<string, mixed> */
	private array $data;
    private PrestashopServiceInterface $service;

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
		return (int) ($this->data['id'] ?? 0);
	}

	public function toArray(): array
	{
		return $this->data;
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
		$this->data['id'] = $this->data['id'] ?? null ? $this->data['id'] : null;
		$this->data['products'] = (array) ($this->data['products'] ?? []);
		$this->data['id_customer'] = empty($this->data['id_customer'] ?? null) ? null : $this->data['id_customer'];
		$this->data['id_guest'] = empty($this->data['id_guest'] ?? null) ? null : $this->data['id_guest'];

		$customizations = [];
		foreach($this->data['customizations'] ?? [] as $customization) {
			$customizations[] = [
				'id_customization_field' => $customization['id'],
				'value' => $customization['value']
			];
		}
		$this->data['products'][0]['customizations'] = $customizations;
	}


	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		$dataPayLoad = $this->data;
		$dataPayLoad['id_cart'] = $this->data['id'];
		$replaceProducts = $this->data['replace_products'] ?? false;
		if (!is_bool($replaceProducts)) {
			throw new \InvalidArgumentException('replace_products must be a boolean.');
		}
		$dataPayLoad['replace_products'] = $replaceProducts;
		foreach (['id_address_delivery', 'id_address_invoice'] as $addressField) {
			$addressId = filter_var($this->data[$addressField] ?? null, FILTER_VALIDATE_INT);
			if ($addressId === false || $addressId <= 0) {
				unset($dataPayLoad[$addressField]);
			} else {
				$dataPayLoad[$addressField] = $addressId;
			}
		}
		return new \PS\Webservice\Domain\Object\PayloadServiceData($dataPayLoad, ['id_cart' => 'cart', 'id_customer' => 'customer', 'id_guest' => 'guest']);
	}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

	public function hash(): string
    {
        return md5(json_encode($this->data));
    }
}
