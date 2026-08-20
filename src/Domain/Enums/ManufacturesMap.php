<?php

namespace PS\Webservice\Domain\Enums;

use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;

enum ManufacturesMap: string
{
    case claudia_cascioli = 'claudia cascioli';
    case bruno_felice = 'bruno felice';
    case chiccheria = 'chiccheria';
    case pet_cherie = 'pet cherie';
    case art_and_dog_di_francesca = 'art and dog di francesca';
    case gvresin = 'gvresin';

    /**
     * Restituisce il nome del produttore per un dato ID
     */
    public static function getManufacturer(int $id): string
    {
        $manufacturer = Manufacturer::where('id_manufacturer', $id)->first();
        if ($manufacturer) {
            return strtolower(str_replace(' ', '-', $manufacturer->details->name));
        }

        return "unknown";
    }

}