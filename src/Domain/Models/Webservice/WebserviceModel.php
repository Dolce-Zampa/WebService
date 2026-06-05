<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Models\Webservice;

use Illuminate\Database\Eloquent\Model;

class WebserviceModel extends Model
{
    protected $prefix = env('PS_TABLE_PREFIX', 'ps_');

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->prefix = env('PS_TABLE_PREFIX', 'ps_');
        $this->setTable($this->prefix . $this->getTable());
    }

}