<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS;

use PS\Webservice\Domain\Models\PS\PsTable;
use PS\Webservice\Domain\Models\PS\Suppliers\Supplier;
use PS\Webservice\Service\PS\Customer;
use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;

class Address extends PsTable
{
    protected $table = 'address';
    protected $primaryKey = 'id_address';
    protected $fillable = [
        'id_supplier',
        'id_country',
        'address',
        'city',
        'zip_code',
        'country',
        'state',
        'alias',
        'lastname',
        'firstname',
        'vat_number',
        'dni',
        'phone_mobile',
        'iban',
        'address1',
        'date_add',
        'date_upd',
    ];

    public function supplier()
    {
        return $this->hasOne(Supplier::class, 'id_supplier', 'id_supplier');
    }

    public function manufacturer()
    {
        return $this->hasOne(Manufacturer::class, 'id_manufacturer', 'id_manufacturer');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'id_customer', 'id_customer');
    }
}