<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

class OptionEntity implements ObjectInterface
{
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

	public function getAttributeGroupId(): int
	{
		return (int) ($this->data['id_attribute_group'] ?? 0);
	}

	public function getColor(): string
	{
		return (string) ($this->data['color'] ?? '');
	}

	public function getPosition(): int
	{
		return (int) ($this->data['position'] ?? 0);
	}

	public function getName(): string
	{
		return (string) ($this->data['name'] ?? '');
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
		//FIXME: ora controlliamo id_attribute_group ma dovremmo trovare un modo per capire se è un colore o un'altra opzione
		$type = 'custom';
		if($this->data['id_attribute_group'] == 8) {
			$type = 'dimensions';
		}

		if($this->data['id_attribute_group'] == 1) {
			$type = 'size';
		}

		if($this->data['id_attribute_group'] == 7) {
			$type = 'length';
		}

		if($this->data['id_attribute_group'] == 2) {
			$type = 'color';
		}

		if($this->data['id_attribute_group'] == 9) {
			$type = 'material';
		}

		$this->data = [
			'id' => (int) ($this->data['id'] ?? 0),
			'id_attribute_group' => (int) ($this->data['id_attribute_group'] ?? 0),
			'color' => (string) ($this->data['color'] ?? ''),
			'type' => $type,
			'position' => (int) ($this->data['position'] ?? 0),
			'name' => (string) ($this->data['name'] ?? ''),
		];
	}

	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray());
	}
}
