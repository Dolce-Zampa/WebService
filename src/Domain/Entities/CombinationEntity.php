<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\Enums\ImageTail;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\ProductBuilder;

class CombinationEntity implements ObjectInterface
{
    use ProductBuilder;

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

	public function getProductId(): int
	{
		return (int) ($this->data['id_product'] ?? 0);
	}

	public function getReference(): string
	{
		return (string) ($this->data['reference'] ?? '');
	}

	public function getPrice(): float
	{
		return (float) ($this->data['price'] ?? 0.0);
	}

	public function getMinimalQuantity(): int
	{
		return (int) ($this->data['minimal_quantity'] ?? 1);
	}

	/** @return array<int, array{id:int}> */
	public function getProductOptionValues(): array
	{
		/** @var array<int, array{id:int}> $values */
		$values = $this->data['associations']['product_option_values'] ?? [];
		return $values;
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

	/**
	 * @return array<string, mixed>
	 */
	public function normalizeData(): void
	{
        $this->buildOptionValues();
        // $this->buildImageLink([ImageTail::ORIGINAL]);
	}

	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray());
	}

}