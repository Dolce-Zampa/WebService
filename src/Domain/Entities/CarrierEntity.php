<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\Object\Carrier;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

class CarrierEntity implements ObjectInterface
{
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

	public function __get(string $name): mixed
	{
		if (!array_key_exists($name, $this->data)) {
			throw new \InvalidArgumentException('No argument found with ' . $name);
		}

		return $this->data[$name];
	}

    public function toArray(): array
    {
        return (new Carrier($this->data))->toArray();
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function normalizeData(): void
    {
        // Extract name from language array if needed
        if (isset($this->data['name']) && is_array($this->data['name'])) {
            $this->data['name'] = $this->extractLanguageValue($this->data['name']);
        }

        // Extract delay from language array if needed
        if (isset($this->data['delay']) && is_array($this->data['delay'])) {
            $this->data['delay'] = $this->extractLanguageValue($this->data['delay']);
        }

        $this->data['price_with_tax'] = "6.10"; //FIXME: this is a temporary value, as the price is not provided by the API response. You may want to calculate it based on other data or fetch it from a different endpoint.
    }

    private function extractLanguageValue(array $languageArray): string
    {
        // Try to get the first language value
        if (isset($languageArray['language'])) {
            if (is_array($languageArray['language'])) {
                // Multiple languages
                $firstLang = reset($languageArray['language']);
                return is_array($firstLang) && isset($firstLang['value']) 
                    ? (string) $firstLang['value'] 
                    : (string) $firstLang;
            }
            // Single language
            return is_array($languageArray['language']) && isset($languageArray['language']['value'])
                ? (string) $languageArray['language']['value']
                : (string) $languageArray['language'];
        }

        // Fallback: return first value
        return (string) reset($languageArray);
    }

	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray());
	}
}
