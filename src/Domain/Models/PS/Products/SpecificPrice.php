<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS\Products;

use PS\Webservice\Domain\Models\PS\PsTable;

final class SpecificPrice extends PsTable
{
    protected $table = 'specific_price';
    protected $primaryKey = 'id_specific_price';
}
