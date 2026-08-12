<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

use PS\Webservice\Domain\Models\PS\PsTable;

class User extends PsTable
{
	protected $table = 'customer';
	protected $primaryKey = 'id_customer';
	public $timestamps = false;
	protected $guarded = [];

}