<?php

namespace PS\Webservice\Domain\Enums;

use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;

enum ManufacturesMap: string
{
    case claudia_cascioli = 'Claudia Cascioli';
    case bruno_felice = 'Bruno Felice';
    case chiccheria = 'Chiccheria';
    case pet_cherie = 'Pet Cherie';
    case art_and_dog_di_francesca = 'Art And Dog Di Francesca';
    case gvresin = 'Gvresin';

    /**
     * Restituisce il nome del produttore per un dato ID
     */
    public static function getManufacturer(int $id): string
    {
        $manufacturer = Manufacturer::where('id_manufacturer', $id)->first();
        if ($manufacturer) {
            return $manufacturer->name;
        }

        return "unknown";
    }

}