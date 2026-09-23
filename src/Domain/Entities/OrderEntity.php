<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\Entities\CartRuleEntity;
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
		$customer = CustomerEntity::create($this->data['customer'], $this->service);
		$data = [
			'id' => $this->data['id'],
			'id_carrier' => $this->data['id_carrier'], //
			'id_guest' => $this->data['id_guest'],
			'id_customer' => $this->data['id_customer'],
			'reference' => (string) $this->data['reference'],
			'id_cart' => $this->data['id_cart'],
			'current_state' => (int) $this->data['current_state'],
			'date_add' => (string) $this->data['date_add'],
			'total_paid_tax_incl' => (float) $this->data['total_paid_tax_incl'],
			'total_paid_tax_excl' => (float) $this->data['total_paid_tax_excl'],
			'customer' => $customer->toArray(),
			'id_lang' => $this->data['id_lang'] ?? 1, //FIXME: id_lang is not always present in the order data, should be determined based on the customer or cart data
		];
		$data['customer']['delivery_address'] = $this->data['delivery_address'];
		$data['customer']['invoice_address'] = $this->data['invoice_address'];
		$data['customer']['phone'] = $this->data['customer']['phone_mobile'] ?? null; //FIXME: phone_mobile is used as a fallback for phone, but ideally should be determined based on the customer data

        $currentCartRule = $this->currentCartRule();
        $data['cartRules'] = CartRuleEntity::create($currentCartRule, $this->service) ?? [];


		$this->data = $data;
	}

	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->data, ['id' => 'order', 'id_cart' => 'cart']);
	}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

	public function hash(): string
    {
        return md5(json_encode($this->data));
    }

	public function getCartRules(): ?CartRuleEntity
	{
		return $this->data['cartRules'] ?? null;
	}

    private function currentCartRule(): array
    {
        $cartRuleSettings = file_get_contents(__DIR__ . '/../../../storage/configs/cart_rules.json');
        $cartRules = CartRuleEntity::create(json_decode($cartRuleSettings, true), $this->service);
        return $cartRules->toArray() ?? [];
    }
}
