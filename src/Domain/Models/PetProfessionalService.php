<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class PetProfessionalService extends Model
{
    protected $table = 'pet_professional_services';

    public $timestamps = true;

    protected $fillable = [
        'first_name',
        'last_name',
        'company_name',
        'vat_number',
        'fiscal_code',
        'fiscal_data',
        'address',
        'service_type',
        'description',
        'media',
    ];

    protected $casts = [
        'fiscal_data' => 'array',
        'media' => 'array',
    ];
}
