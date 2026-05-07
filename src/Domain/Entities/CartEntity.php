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
*    "replace_products": true,
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
		$this->data = [
			'id' => $this->encodeId($this->data['id'], 'cart'),
			'products' => (array) ($this->data['products'] ?? []),
		];
	}

	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->data, ['id' => 'cart']);
	}
}
