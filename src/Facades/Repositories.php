<?php
declare(strict_types=1);

namespace PS\Webservice\Facades;

final class Repositories extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'public-repositories';
    }

    public static function customer(): \PS\Webservice\Repositories\CustomerRepository
    {
        return new \PS\Webservice\Repositories\CustomerRepository(
            static::getFacadeRoot()->getDbInstance()
        );
    }
}