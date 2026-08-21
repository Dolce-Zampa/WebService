<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

class AccessoryEntity extends Entity implements ObjectInterface
{
	/** @var array<string, mixed> */
	protected array $data;
    protected ?PrestashopServiceInterface $service;

	protected string $cacheTag = 'accessory';

	public static function create(array $data, ?PrestashopServiceInterface $service): self
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
			'id' => (int) ($this->data['id'] ?? 0),
		];
	}
	
	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray());
	}
}
