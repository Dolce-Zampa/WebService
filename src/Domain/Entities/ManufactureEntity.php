<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;
use PS\Webservice\Domain\Models\PS\Manufacturers\ManufacturerDetail;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

class ManufactureEntity extends Entity implements ObjectInterface
{
	/** @var array<string, mixed> */
	protected array $data;
    protected ?PrestashopServiceInterface $service;

	protected string $cacheTag = 'manufacturer';

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

	public function normalizeData(): void
	{
		$this->data['slug'] = $this->data['link_rewrite'] ?? '';
		$this->data['image'] = $this->getAvatar();
		$this->data['firstname'] = $this->data['first_name'];
		$this->data['lastname'] = $this->data['last_name'];
		$this->data['newsletter'] = (bool) ($this->data['newsletter'] ?? false);
		$this->data['id_country'] = (int) 11; //FIXME: Hardcoded country ID, should be dynamic based on actual data
		
		$isPremium = Manufacturer::where('id_manufacturer', $this->getId())
			->where('premium', true)
			->exists();
		
		if($isPremium || $this->data['premium'] == true) {
			$this->data['premium'] = true;
			$this->data['link_domain'] = "https://". $this->data['link_rewrite'] . 'dolcezampa.com';
		} else {
			$this->data['premium'] = false;
		}
	}

	private function getAvatar(): ?string
	{
		$avatar = ManufacturerDetail::getAvatar($this->getId());
		return $avatar;
	}
	
	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray());
	}

	public function buildReviews(): void
	{
		$reviews = $this->service->getReviews($this->getId());
		
        $this->data['reviews'] = [];
        foreach ($reviews as $reviewEntity) {
            $this->data['reviews'][] = $reviewEntity->toArray();
        }
	}
}
