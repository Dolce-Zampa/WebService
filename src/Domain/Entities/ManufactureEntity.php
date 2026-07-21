<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\Brand;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

class ManufactureEntity implements ObjectInterface
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
		$extensions = ['jpg', 'png', 'jpeg', 'gif'];
		$this->data['slug'] = $this->data['link_rewrite'] ?? '';

		$filename = $this->data['slug'];
		foreach ($extensions as $ext) {
			$domanin = "https://www.dolcezampa.com";
			$imageUrl = "{$domanin}/img/m/{$filename}.{$ext}";
			if (@file_get_contents($imageUrl)) {
				$this->data['image'] = $imageUrl;
				break;
			}
		}
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
