<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use Illuminate\Support\Facades\DB;
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
        $normalized = [
            'email' => $customer['email'],
            'password' => $customer['password'] ?? null,
            'firstname' => $customer['firstname'],
            'lastname' => $customer['lastname'],
            'phone' => $phone,
            'newsletter' => (bool) ($customer['newsletter'] ?? false)
        ];

        if(isset($customer['id'])) {
            $normalized['id'] = $customer['id'];
        }
        if (isset($customer['id_lang'])) {
            $normalized['id_lang'] = (int) $customer['id_lang'];
        }

        if (is_array($deliveryAddress)) {
            $normalized['delivery_address'] = $this->normalizeDeliveryAddress($deliveryAddress);
        }

        if (isset($customer['invoice_address']) && is_array($customer['invoice_address'])) {
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
        foreach (['address1', 'city', 'postcode'] as $field) {
            if (!isset($deliveryAddress[$field]) || !is_string($deliveryAddress[$field]) || trim($deliveryAddress[$field]) === '') {
                throw new \InvalidArgumentException("Address {$field} is required");
            }
        }

        $idCountry = filter_var($deliveryAddress['id_country'] ?? null, FILTER_VALIDATE_INT);
        if (($idCountry === false || $idCountry <= 0) && !empty($deliveryAddress['country'])) {
            $idCountry = DB::table('country')
                ->where('iso_code', strtoupper(trim((string) $deliveryAddress['country'])))
                ->value('id_country');
        }
        if ($idCountry === false || $idCountry <= 0) {
            throw new \InvalidArgumentException('Address country is required and must match a configured country');
        }

        $idState = $deliveryAddress['id_state'] ?? null;
        if (($idState === null || $idState === '') && !empty($deliveryAddress['state'])) {
            $stateCode = trim((string) $deliveryAddress['state']);
            $idState = State::where('id_country', (int) $idCountry)
                ->where(function ($query) use ($stateCode) {
                    $query->where('iso_code', $stateCode)->orWhere('name', $stateCode);
                })
                ->value('id_state');
            if ($idState === null) {
                throw new \InvalidArgumentException('Address state does not match the configured country');
            }
        }
        if ($idState !== null && $idState !== '' && (!is_numeric($idState) || (int) $idState <= 0)) {
            throw new \InvalidArgumentException('Address id_state must be a positive integer when provided');
        }

        return [
            'alias' => (string) ($deliveryAddress['alias'] ?? ''),
            'firstname' => (string) ($deliveryAddress['firstname'] ?? $this->data['firstname']),
            'lastname' => (string) ($deliveryAddress['lastname'] ?? $this->data['lastname']),
            'address1' => (string) trim(str_replace("\xc2\xa0", ' ', str_replace(',', ' ', $deliveryAddress['address1']))),
            'city' => $deliveryAddress['city'],
            'postcode' => $deliveryAddress['postcode'],
            'id_country' => (int) $idCountry,
            'phone_mobile' => $deliveryAddress['phone_mobile'] ?? $deliveryAddress['phone'] ?? $this->data['phone'],
            'id_state' => $idState === null || $idState === '' ? null : (int) $idState,
        ];
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
