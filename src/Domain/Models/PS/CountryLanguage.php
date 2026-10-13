<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS;

final class CountryLanguage extends PsTable
{
    protected $table = 'country_lang';
    protected $primaryKey = null;
    public $incrementing = false;
    protected $guarded = [];
}
