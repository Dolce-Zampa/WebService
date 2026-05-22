<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

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
			throw new \InvalidArgumentException('No argument found with ' . $name);
		}

		return $this->data[$name];
	}

	public function normalizeData(): void
	{
            $this->data = $this->normalizeCustomerPayload($this->data);
	}

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function normalizeCustomerPayload(array $customer): array
    {
        $normalized = [
            'email' => (string) $customer['email'],
            'password' => (string) isset($customer['password']) ? $customer['password'] : '', //FIXME: generate a random password if not provided, to allow account creation from the order confirmation page
            'firstname' => (string) $customer['firstname'],
            'lastname' => (string) $customer['lastname'],
            'phone' => (string) $customer['phone'],
            'newsletter' => (bool) $customer['newsletter'],
        ];

        if(isset($customer['id'])) {
            $normalized['id'] = $this->encodeId($customer['id'], 'customer');
        }

        $customer['delivery_address']['phone_mobile'] = $customer['phone'] ?? null;
        if (isset($customer['delivery_address']) && is_array($customer['delivery_address'])) {
            $normalized['delivery_address'] = $this->normalizeDeliveryAddress($customer['delivery_address']);
        }

        if (isset($customer['invoice_address']) && is_array($customer['invoice_address'])) {
            $normalized['invoice_address'] = $this->normalizeDeliveryAddress($customer['invoice_address']);
        } else {
            $normalized['invoice_address'] = null;
        }

        $normalized['invoice_address'] = null;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $deliveryAddress
     * @return array<string, mixed>
     */
    private function normalizeDeliveryAddress(array $deliveryAddress): array
    {
        $deliveryAddressData = '';
        if(!empty($deliveryAddress['address1'])) {
            $deliveryAddressData = $deliveryAddress['address1'];
        }
        
        return [
            'alias' => (string) ($deliveryAddress['alias'] ?? 'home'),
            'firstname' => (string) $this->data['firstname'],
            'lastname' => (string) $this->data['lastname'],
            'address1' => (string) trim(str_replace("\xc2\xa0", ' ', str_replace(',', ' ', $deliveryAddressData))),
            'city' => (string) $deliveryAddress['city'],
            'postcode' => (string) $deliveryAddress['postcode'],
            'id_country' => 10, //FIXME: Default country ID should be determined dynamically based on the delivery address details
            'phone_mobile' => (string) ($this->data['phone'] ?? ''),
            'id_state' => 228, //FIXME: State ID should be determined dynamically based on the delivery address details
        ];
    }

    public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
    {
        return new \PS\Webservice\Domain\Object\PayloadServiceData($this->data, ['id' => 'customer']);
    }
}
