<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Models\PS;

final class Configuration extends PsTable
{
    protected $table = 'configuration';
    protected $primaryKey = 'id_configuration';
    protected $guarded = [];

    public static function valueFor(string $name): ?string
    {
        $value = static::query()->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }
}
