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
            'email' => $customer['email'],
            'password' => $customer['password'] ?? null,
            'firstname' => $customer['firstname'],
            'lastname' => $customer['lastname'],
            'phone' => is_string($phone) ? $phone : '',
            'newsletter' => (bool) ($customer['newsletter'] ?? false)
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

        if (isset($customer['delivery_address']) && !is_array($deliveryAddress)) {
            throw new \InvalidArgumentException('Customer delivery_address must be an object');
        }
        if (is_array($deliveryAddress)) {
            $normalized['delivery_address'] = $this->normalizeDeliveryAddress($deliveryAddress, is_string($phone) ? $phone : null);
        }

        if (isset($customer['invoice_address'])) {
            if (!is_array($customer['invoice_address'])) {
                throw new \InvalidArgumentException('Customer invoice_address must be an object');
            }
            $normalized['invoice_address'] = $this->normalizeDeliveryAddress($customer['invoice_address'], is_string($phone) ? $phone : null);
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
        if ($idState === '' || $idState === 0 || $idState === '0') {
            $idState = null;
        }
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

        $normalized = [
            'alias' => (string) ($deliveryAddress['alias'] ?? ''),
            'firstname' => (string) ($deliveryAddress['firstname'] ?? $this->data['firstname']),
            'lastname' => (string) ($deliveryAddress['lastname'] ?? $this->data['lastname']),
            'address1' => (string) trim(str_replace("\xc2\xa0", ' ', str_replace(',', ' ', $deliveryAddress['address1']))),
            'city' => $deliveryAddress['city'],
            'postcode' => $deliveryAddress['postcode'],
            'id_country' => (int) $idCountry,
            'phone_mobile' => (string) ($deliveryAddress['phone_mobile'] ?? $deliveryAddress['phone'] ?? $fallbackPhone ?? ''),
            'id_state' => $idState === null || $idState === '' ? null : (int) $idState,
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
