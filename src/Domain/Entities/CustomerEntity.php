<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\Models\PS\State;
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
            if (($this->data['uuid'] ?? null) === null) {
                $this->data['uuid'] = Uuid::uuid4()->toString();
            }
	}

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function normalizeCustomerPayload(array $customer): array
    {
        foreach (['email', 'firstname', 'lastname'] as $field) {
            if (!isset($customer[$field]) || !is_string($customer[$field]) || trim($customer[$field]) === '') {
                throw new \InvalidArgumentException("Customer {$field} is required");
            }
        }

        $deliveryAddress = $customer['delivery_address'] ?? null;
        $phone = $customer['phone'] ?? null;
        if (!is_string($phone) || trim($phone) === '') {
            $phone = is_array($deliveryAddress)
                ? ($deliveryAddress['phone_mobile'] ?? $deliveryAddress['phone'] ?? null)
                : null;
        }
        $countryId = is_array($deliveryAddress)
            && isset($deliveryAddress['id_country'])
            && is_numeric($deliveryAddress['id_country'])
            && (int) $deliveryAddress['id_country'] > 0
            ? (int) $deliveryAddress['id_country']
            : null;
        $country = is_array($deliveryAddress) ? ($deliveryAddress['country'] ?? null) : null;
        if (is_array($deliveryAddress) && $countryId === null && (!is_string($country) || trim($country) === '')) {
            throw new \InvalidArgumentException('Address country is required');
        }
        $normalized = [
            'email' => (string) ($customer['email'] ?? ''),
            'password' => $customer['password'] ?? null,
            'firstname' => (string) ($customer['firstname'] ?? ''),
            'lastname' => (string) ($customer['lastname'] ?? ''),
            'phone' => is_string($phone) ? $phone : '',
            'newsletter' => (bool) ($customer['newsletter'] ?? false),
        ];

        if(isset($customer['id'])) {
            $normalized['id'] = $customer['id'];
        }
        if (isset($customer['id_lang'])) {
            $normalized['id_lang'] = (int) $customer['id_lang'];
        }
        foreach (['sub', 'birthday', 'is_seller', 'id_gender', 'newsletter_date_add'] as $field) {
            if (array_key_exists($field, $customer)) {
                $normalized[$field] = $customer[$field];
            }
        }

        if (is_array($customer['delivery_address'] ?? null)) {
            $normalized['delivery_address'] = $this->normalizeDeliveryAddress($customer['delivery_address'], $phone);
        }

        if (is_array($customer['invoice_address'] ?? null)) {
            $normalized['invoice_address'] = $this->normalizeDeliveryAddress($customer['invoice_address'], $phone);
        } else {
            $normalized['invoice_address'] = null;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $deliveryAddress
     * @return array<string, mixed>
     */
    private function normalizeDeliveryAddress(array $deliveryAddress, ?string $fallbackPhone = null): array
    {
        $countryId = isset($deliveryAddress['id_country'])
            && is_numeric($deliveryAddress['id_country'])
            && (int) $deliveryAddress['id_country'] > 0
            ? (int) $deliveryAddress['id_country']
            : 10;

        $normalized = [
            'alias' => (string) ($deliveryAddress['alias'] ?? 'home'),
            'firstname' => (string) ($this->data['firstname'] ?? ''),
            'lastname' => (string) ($this->data['lastname'] ?? ''),
            'address1' => (string) trim(str_replace("\xc2\xa0", ' ', str_replace(',', ' ', (string) ($deliveryAddress['address1'] ?? '')))),
            'city' => (string) ($deliveryAddress['city'] ?? ''),
            'postcode' => (string) ($deliveryAddress['postcode'] ?? ''),
            'id_country' => $countryId,
            'phone_mobile' => (string) ($deliveryAddress['phone_mobile'] ?? $deliveryAddress['phone'] ?? $fallbackPhone ?? ''),
            'id_state' => $this->resolveStateId($deliveryAddress, $countryId),
        ];
        foreach (['address2', 'state', 'country'] as $field) {
            if (isset($deliveryAddress[$field]) && is_string($deliveryAddress[$field])) {
                $normalized[$field] = $deliveryAddress[$field];
            }
        }

        return $normalized;
    }

    /**
     * Resolves a province supplied by the checkout without ever falling back
     * to an unrelated hard-coded state.
     */
    private function resolveStateId(array $address, int $countryId): int
    {
        if (isset($address['id_state']) && is_numeric($address['id_state']) && (int) $address['id_state'] > 0) {
            return (int) $address['id_state'];
        }

        $state = trim((string) ($address['state'] ?? ''));
        if ($state === '' || $countryId <= 0) {
            return 0;
        }

        try {
            $idState = State::query()
                ->where('id_country', $countryId)
                ->where(function ($query) use ($state) {
                    $query->where('iso_code', strtoupper($state))
                        ->orWhere('name', $state);
                })
                ->value('id_state');

            return is_numeric($idState) && (int) $idState > 0 ? (int) $idState : 0;
        } catch (\Throwable) {
            return 0;
        }
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
