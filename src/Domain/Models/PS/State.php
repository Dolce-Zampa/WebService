<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS;

use PS\Webservice\Domain\Models\PS\PsTable;

class State extends PsTable
{
    protected $table = 'state';
    protected $primaryKey = 'id_state';
}