<?php

namespace PS\Webservice\Domain\Enums;

enum ManufacturesMap: string
{
    case claudia_cascioli = 'claudia cascioli';
    case bruno_felice = 'bruno felice';
    case chiccheria = 'chiccheria';
    case pet_cherie = 'pet cherie';
    case art_and_dog_di_francesca = 'art and dog di francesca';
    case gvresin = 'gvresin';

    /**
     * Mappa i produttori con i relativi ID
     */
    private const MANUFACTURER_IDS = [
        'claudia cascioli' => [6],
        'bruno felice' => [5],
        'chiccheria' => [11],
        'pet cherie' => [4],
        'art and dog di francesca' => [9],
        'gvresin' => [10],
    ];

    /**
     * Restituisce il nome del produttore per un dato ID
     */
    public static function getManufacturer(int $id): string
    {
        foreach (self::MANUFACTURER_IDS as $manufacturerName => $ids) {
            if (in_array($id, $ids, true)) {
                return $manufacturerName;
            }
        }

        return 'unknown';
    }

    /**
     * Verifica se un ID appartiene a un produttore specifico
     */
    public static function isInManufacturer(int $id, string $manufacturerName): bool
    {
        $manufacturer = self::tryFrom($manufacturerName);
        if (!$manufacturer) {
            return false;
        }

        $ids = self::MANUFACTURER_IDS[$manufacturer->value] ?? [];
        return in_array($id, $ids, true);
    }

    /**
     * Ottiene tutti gli ID di un produttore specifico
     */
    public static function getManufacturerIds(string $manufacturerName): array
    {
        $manufacturer = self::tryFrom($manufacturerName);
        if (!$manufacturer) {
            return [];
        }

        return self::MANUFACTURER_IDS[$manufacturer->value] ?? [];
    }

    /**
     * Restituisce tutti i produttori con i relativi ID
     */
    public static function getAllManufacturers(): array
    {
        return self::MANUFACTURER_IDS;
    }

    /**
     * Metodi dedicati per ogni produttore per retrocompatibilità
     */
    public static function claudiaCascioli(int $id): string
    {
        if (in_array($id, self::MANUFACTURER_IDS['claudia cascioli'], true)) {
            return self::claudia_cascioli->value;
        }

        return self::getManufacturer($id);
    }

    public static function brunoFelice(int $id): string
    {
        if (in_array($id, self::MANUFACTURER_IDS['bruno felice'], true)) {
            return self::bruno_felice->value;
        }

        return self::getManufacturer($id);
    }

    public static function chiccheria(int $id): string
    {
        if (in_array($id, self::MANUFACTURER_IDS['chiccheria'], true)) {
            return self::chiccheria->value;
        }

        return self::getManufacturer($id);
    }

    public static function petCherie(int $id): string
    {
        if (in_array($id, self::MANUFACTURER_IDS['pet cherie'], true)) {
            return self::pet_cherie->value;
        }

        return self::getManufacturer($id);
    }

    public static function artAndDogDiFrancesca(int $id): string
    {
        if (in_array($id, self::MANUFACTURER_IDS['art and dog di francesca'], true)) {
            return self::art_and_dog_di_francesca->value;
        }

        return self::getManufacturer($id);
    }

    public static function gvresin(int $id): string
    {
        if (in_array($id, self::MANUFACTURER_IDS['gvresin'], true)) {
            return self::gvresin->value;
        }

        return self::getManufacturer($id);
    }
}