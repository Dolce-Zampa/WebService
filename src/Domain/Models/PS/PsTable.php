<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS;

use Illuminate\Database\Eloquent\Model;

class PsTable extends Model
{
    public $timestamps = false;
    protected $table = 'customer';

    public static function tableName(): string
    {
        return (new self())->table;
    }

}