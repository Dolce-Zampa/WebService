<?php

namespace PS\Webservice\Domain\Enums;

enum CategoriesMap: string
{
    case abbigliamento = 'abbigliamento';
    case borse = 'borse';
    case cucce = 'cucce';
    case pettorine = 'pettorine';
    case altro = 'altro';
    case promozioni = 'promozioni';
    case set_completi = 'set_completi';
    case accessori = 'accessori';

    /**
     * Mappa le categorie con i relativi ID
     */
    private const CATEGORY_IDS = [
        'abbigliamento' => [10, 15, 16, 21, 22, 23, 26, 28],
        'borse' => [11],
        'cucce' => [12, 31],
        'pettorine' => [13, 17, 18, 19, 20, 25, 29],
        'altro' => [14],
        'promozioni' => [24],
        'set_completi' => [27],
        'accessori' => [32, 30, 34],
    ];

    /**
     * Restituisce il nome della categoria per un dato ID
     */
    public static function getCategory(int $id): string
    {
        foreach (self::CATEGORY_IDS as $categoryName => $ids) {
            if (in_array($id, $ids, true)) {
                return $categoryName;
            }
        }

        return 'unknown';
    }

    /**
     * Verifica se un ID appartiene a una categoria specifica
     */
    public static function isInCategory(int $id, string $categoryName): bool
    {
        $category = self::tryFrom($categoryName);
        if (!$category) {
            return false;
        }

        $ids = self::CATEGORY_IDS[$category->value] ?? [];
        return in_array($id, $ids, true);
    }

    /**
     * Ottiene tutti gli ID di una categoria specifica
     */
    public static function getCategoryIds(string $categoryName): array
    {
        $category = self::tryFrom($categoryName);
        if (!$category) {
            return [];
        }

        return self::CATEGORY_IDS[$category->value] ?? [];
    }

    /**
     * Restituisce tutte le categorie con i relativi ID
     */
    public static function getAllCategories(): array
    {
        return self::CATEGORY_IDS;
    }

    /**
     * Categorie specifiche con metodi dedicati per retrocompatibilità
     */
    public static function abbigliamento(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['abbigliamento'], true)) {
            return self::abbigliamento->value;
        }

        return self::getCategory($id);
    }

    public static function borse(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['borse'], true)) {
            return self::borse->value;
        }

        return self::getCategory($id);
    }

    public static function cucce(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['cucce'], true)) {
            return self::cucce->value;
        }

        return self::getCategory($id);
    }

    public static function pettorine(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['pettorine'], true)) {
            return self::pettorine->value;
        }

        return self::getCategory($id);
    }

    public static function altro(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['altro'], true)) {
            return self::altro->value;
        }

        return self::getCategory($id);
    }

    public static function promozioni(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['promozioni'], true)) {
            return self::promozioni->value;
        }

        return self::getCategory($id);
    }

    public static function setCompleti(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['set_completi'], true)) {
            return self::set_completi->value;
        }

        return self::getCategory($id);
    }

    public static function accessori(int $id): string
    {
        if (in_array($id, self::CATEGORY_IDS['accessori'], true)) {
            return self::accessori->value;
        }

        return self::getCategory($id);
    }
}