<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities\Validations;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\ProductEntity;

final class ProductValidator {
    /** Campi scalari obbligatori a livello root */
    private const REQUIRED_FIELDS = [
        'id', 'id_manufacturer', 'id_supplier', 'id_category_default',
        'reference', 'price', 'name', 'link_rewrite', 'description',
        'description_short', 'active', 'url',
    ];

    /** Chiavi obbligatorie dentro associations */
    private const REQUIRED_ASSOCIATIONS = [
        'categories', 'images',
        'stock_availables',
    ];

    /**
     * @return string[] elenco degli errori trovati (vuoto se tutto ok)
     */
    public static function validate(ProductEntity $product): array
    {
        $errors = [];
        $data = $product->toArray();

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors[] = "Campo mancante o vuoto: {$field}";
            }
        }

        if(count($errors) > 0) {
            Log::error("Product validation failed for product ID {$product->getId()}: " . implode(", ", $errors));
        }

        return $errors;
    }

    public static function isValid(ProductEntity $product): bool
    {
        return empty(self::validate($product));
    }
}