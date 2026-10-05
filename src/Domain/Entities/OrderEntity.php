<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use Illuminate\Support\Facades\DB;
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
		$customerData = $this->data['customer'] ?? [];
		$customerData['delivery_address'] = $this->data['delivery_address'] ?? $customerData['delivery_address'] ?? null;
		$customerData['invoice_address'] = $this->data['invoice_address'] ?? $customerData['invoice_address'] ?? null;
		$customerData['phone'] = $customerData['phone'] ?? $customerData['phone_mobile'] ?? null;
		$customer = CustomerEntity::create($customerData, $this->service);
		$idLang = null;
		foreach ([$this->data['id_lang'] ?? null, $this->data['cart']['id_lang'] ?? null, $customerData['id_lang'] ?? null] as $languageId) {
			if (is_numeric($languageId) && (int) $languageId > 0) {
				$idLang = (int) $languageId;
				break;
			}
		}
		if ($idLang === null) {
			$idLang = DB::table('configuration')->where('name', 'PS_LANG_DEFAULT')->value('value');
		}
		if (!is_numeric($idLang) || (int) $idLang <= 0) {
			throw new \InvalidArgumentException('Order language is required and must be a positive integer');
		}
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
			'expires_at' => $this->data['expires_at'] ?? time() + 3600,
			'recovery_attempt' => $this->data['recovery_attempt'] ?? false,
			'id_lang' => (int) $idLang,
		];
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
