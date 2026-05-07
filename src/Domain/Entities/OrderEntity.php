<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

class OrderEntity implements ObjectInterface
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

	public static function createFromCart(CartEntity $cart, PrestashopServiceInterface $service): self
	{
		return new self($cart->toArray(), $service);
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
		$customer = CustomerEntity::create($this->data['order']['customer'], $this->service);
		$this->data = [
			'id' => $this->encodeId($this->data['id'], 'order'),
			'reference' => (string) $this->data['reference'],
			'id_cart' => $this->encodeId($this->data['id_cart'], 'cart'),
			'current_state' => (int) $this->data['current_state'],
			'date_add' => (string) $this->data['date_add'],
			'total_paid_tax_incl' => (float) $this->data['total_paid_tax_incl'],
			'total_paid_tax_excl' => (float) $this->data['total_paid_tax_excl'],
			'customer' => $customer->toArray(),
			'id_lang' => (int) $this->data['id_lang'],
		];
	}

	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->data, ['id' => 'order', 'id_cart' => 'cart']);
	}
}
