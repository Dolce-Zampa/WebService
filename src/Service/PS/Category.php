<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Collection;

class Category extends PrestashopService implements PrestashopServiceInterface
{
    public function categoriesList(array $displayOptions = ['display' => 'full']): Collection
    {
        if (!empty($displayOptions)) {
            $queryString = http_build_query($displayOptions);
            $this->httpService->setUrl("/categories?{$queryString}");
        } else {
            $this->httpService->setUrl('/categories');
        }

        $response = $this->httpService->invoke('GET');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to retrieve categories: ' . $response->getHttpCode());
        }

        $collection = new Collection();
        foreach (($response->toArray()['categories'] ?? []) as $categoryData) {
            $collection->push($this->normalizeCategoryData($categoryData));
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $categoryData
     * @return array<string, mixed>
     */
    private function normalizeCategoryData(array $categoryData): array
    {
        $id = (int) ($categoryData['id'] ?? 0);
        $name = $this->extractTranslatedValue($categoryData['name'] ?? '');
        $slug = $this->extractTranslatedValue($categoryData['link_rewrite'] ?? '');
        $description = $this->extractTranslatedValue($categoryData['description'] ?? '');
        $additionalDescription = $this->extractTranslatedValue($categoryData['additional_description'] ?? '');
        $metaTitle = $this->extractTranslatedValue($categoryData['meta_title'] ?? '');

        return [
            'id' => $id,
            'parent_id' => (int) ($categoryData['id_parent'] ?? 0),
            'name' => $name,
            'url' => $this->buildCategoryUrl($id, $slug),
            'slug' => $slug,
            'short_description' => $additionalDescription !== '' ? $additionalDescription : $description,
            'description' => $description,
            'title' => $metaTitle !== '' ? $metaTitle : $name,
            'meta_title' => $metaTitle,
            'meta_description' => $this->extractTranslatedValue($categoryData['meta_description'] ?? ''),
            'meta_keywords' => $this->extractTranslatedValue($categoryData['meta_keywords'] ?? ''),
            'active' => $this->normalizeBoolean($categoryData['active'] ?? false),
            'position' => (int) ($categoryData['position'] ?? 0),
            'is_root_category' => $this->normalizeBoolean($categoryData['is_root_category'] ?? false),
            'date_add' => (string) ($categoryData['date_add'] ?? ''),
            'date_upd' => (string) ($categoryData['date_upd'] ?? ''),
            'associations' => is_array($categoryData['associations'] ?? null) ? $categoryData['associations'] : [],
        ];
    }

    private function extractTranslatedValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        if (!is_array($value)) {
            return '';
        }

        if (array_key_exists('value', $value)) {
            return trim((string) $value['value']);
        }

        foreach ($value as $item) {
            $translatedValue = $this->extractTranslatedValue($item);
            if ($translatedValue !== '') {
                return $translatedValue;
            }
        }

        return '';
    }

    private function buildCategoryUrl(int $id, string $slug): string
    {
        $baseUrl = trim((string) ($_ENV['PS_BASE_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            return '';
        }

        if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://' . $baseUrl;
        }

        if ($slug === '') {
            return "{$baseUrl}/{$id}";
        }

        return "{$baseUrl}/{$id}-{$slug}";
    }

    private function normalizeBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
