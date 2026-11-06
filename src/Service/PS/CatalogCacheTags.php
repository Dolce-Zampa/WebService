<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

/** Cache namespaces shared by catalog responses and their underlying data. */
final class CatalogCacheTags
{
    /** @return list<string> */
    public static function forCategories(?string $categories): array
    {
        if ($categories === null || !preg_match('/^\d+(?:\|\d+)*$/', $categories)) {
            return ['product-catalog', 'product-catalog-unscoped'];
        }
        $ids = array_values(array_unique(array_map('intval', explode('|', $categories))));
        if (in_array(0, $ids, true)) {
            return ['product-catalog', 'product-catalog-unscoped'];
        }
        sort($ids);
        return array_merge(['product-catalog'], array_map(static fn (int $id): string => 'catalog-category:' . $id, $ids));
    }

    /** @return list<string> */
    public static function fromFilter(array $options): array
    {
        $value = $options['filter[id_category_default]'] ?? null;
        if (!is_string($value) || !preg_match('/^\[(\d+(?:\|\d+)*)\]$/', $value, $match)) {
            return self::forCategories(null);
        }
        return self::forCategories($match[1]);
    }
}
