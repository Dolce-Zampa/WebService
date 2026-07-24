<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models;

class User extends PsTable
{
	protected $table = 'customer';
	protected $primaryKey = 'id_customer';
	public $timestamps = false;
	protected $guarded = [];

}