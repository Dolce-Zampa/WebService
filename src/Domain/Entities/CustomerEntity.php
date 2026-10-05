<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;
use Ramsey\Uuid\Uuid;

class CustomerEntity implements ObjectInterface
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
			return null;
		}

		return $this->data[$name];
	}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

	public function normalizeData(): void
	{
            $this->data = $this->normalizeCustomerPayload($this->data);
            if($this->data['uuid'] === null) {
                $this->data['uuid'] = Uuid::uuid4()->toString();
            }
	}

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function normalizeCustomerPayload(array $customer): array
    {
        $normalized = [
            'email' => (string) ($customer['email'] ?? ''),
            'password' => (string) ($customer['password'] ?? ''),
            'firstname' => (string) ($customer['firstname'] ?? ''),
            'lastname' => (string) ($customer['lastname'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            'newsletter' => (bool) ($customer['newsletter'] ?? false),
        ];

        if(isset($customer['id'])) {
            $normalized['id'] = $customer['id'];
        }

        if (is_array($customer['delivery_address'] ?? null)) {
            $normalized['delivery_address'] = $this->normalizeDeliveryAddress($customer['delivery_address']);
        }

        if (is_array($customer['invoice_address'] ?? null)) {
            $normalized['invoice_address'] = $this->normalizeDeliveryAddress($customer['invoice_address']);
        } else {
            $normalized['invoice_address'] = null;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $deliveryAddress
     * @return array<string, mixed>
     */
    private function normalizeDeliveryAddress(array $deliveryAddress): array
    {
        $normalized = [
            'alias' => (string) ($deliveryAddress['alias'] ?? 'home'),
            'firstname' => (string) $this->data['firstname'],
            'lastname' => (string) $this->data['lastname'],
            'address1' => (string) trim(str_replace("\xc2\xa0", ' ', str_replace(',', ' ', (string) ($deliveryAddress['address1'] ?? '')))),
            'city' => (string) ($deliveryAddress['city'] ?? ''),
            'postcode' => (string) ($deliveryAddress['postcode'] ?? ''),
            'id_country' => isset($deliveryAddress['id_country']) && is_numeric($deliveryAddress['id_country'])
                ? (int) $deliveryAddress['id_country']
                : 10,
            'phone_mobile' => (string) ($this->data['phone'] ?? ''),
            'id_state' => isset($deliveryAddress['id_state']) && is_numeric($deliveryAddress['id_state'])
                ? (int) $deliveryAddress['id_state']
                : 228,
        ];
        foreach (['address2', 'state', 'country'] as $field) {
            if (isset($deliveryAddress[$field]) && is_string($deliveryAddress[$field])) {
                $normalized[$field] = $deliveryAddress[$field];
            }
        }

        return $normalized;
    }

    public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
    {
        return new \PS\Webservice\Domain\Object\PayloadServiceData($this->data, ['id' => 'customer']);
    }

    public function hash(): string
    {
        return md5(json_encode($this->data));
    }
}
